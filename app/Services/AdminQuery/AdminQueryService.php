<?php

namespace App\Services\AdminQuery;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Admin query tool: turns a structured JSON filter into an Eloquent query.
 *
 * The client never sends SQL. Every field and operator is looked up in the
 * allowlist below; columns come from that list, values are bound, and a
 * field-vs-field comparison uses whereColumn with two allowlisted columns.
 *
 * A filter is a group: {"logic": "and"|"or", "conditions": [...]}, where each
 * condition is another group or {"field": "price", "op": "lt", "value": 10}.
 * To compare with another field send "value": {"field": "stylist_price"}.
 */
class AdminQueryService
{
    public const MAX_DEPTH = 4;
    public const MAX_CONDITIONS = 40;
    public const MAX_PER_PAGE = 200;

    /** Operators each field type accepts. */
    public const OPERATORS = [
        'number' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'empty', 'not_empty'],
        'date' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'between', 'empty', 'not_empty'],
        'text' => ['contains', 'not_contains', 'eq', 'neq', 'starts_with', 'ends_with', 'empty', 'not_empty'],
        'bool' => ['is_true', 'is_false'],
        'ref' => ['in', 'not_in', 'empty', 'not_empty'],
        'enum' => ['in', 'not_in'],
        'count' => ['eq', 'neq', 'lt', 'lte', 'gt', 'gte'],
    ];

    /** Operators that may compare against another field. */
    public const FIELD_OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte'];

    private const SQL_OPS = ['eq' => '=', 'neq' => '!=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];

    private int $conditionCount = 0;

    /** @return list<string> */
    public function entities(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Fields, operators, choices and presets for the admin UI.
     */
    public function schema(): array
    {
        $entities = [];
        foreach ($this->definitions() as $key => $definition) {
            $fields = [];
            foreach ($definition['fields'] as $name => $field) {
                $fields[] = array_filter([
                    'name' => $name,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'operators' => $field['operators'] ?? self::OPERATORS[$field['type']],
                    'comparable' => $this->isColumnField($field) && in_array($field['type'], ['number', 'date', 'text'], true),
                    'options' => isset($field['options']) ? ($field['options'])() : null,
                ], fn ($value) => $value !== null);
            }

            $entities[] = [
                'key' => $key,
                'label' => $definition['label'],
                'fields' => $fields,
                'columns' => $definition['columns'],
                'presets' => $definition['presets'],
                'default_sort' => $definition['default_sort'],
            ];
        }

        return ['entities' => $entities, 'operators' => self::OPERATORS];
    }

    /**
     * Run a filter and return one page of rows.
     *
     * @param  array{field?: string, dir?: string}|null  $sort
     */
    public function run(string $entity, ?array $filter, ?array $sort = null, int $page = 1, int $perPage = 50): array
    {
        $definition = $this->definition($entity);
        $query = $this->build($entity, $filter);

        $sortField = $sort['field'] ?? $definition['default_sort']['field'];
        $sortDir = strtolower((string) ($sort['dir'] ?? $definition['default_sort']['dir'])) === 'asc' ? 'asc' : 'desc';
        $field = $definition['fields'][$sortField] ?? null;
        if (!$field || !$this->isColumnField($field)) {
            throw ValidationException::withMessages(['sort.field' => "Cannot sort by \"{$sortField}\"."]);
        }
        $query->orderBy($this->column($definition, $field), $sortDir)
            ->orderBy($definition['table'] . '.id', $sortDir);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'rows' => collect($paginator->items())->map($definition['row'])->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * The filtered query, unsorted and unpaginated (also used by tests).
     */
    public function build(string $entity, ?array $filter): Builder
    {
        $definition = $this->definition($entity);
        $query = ($definition['query'])();
        $this->conditionCount = 0;

        if ($filter !== null && $filter !== []) {
            $query->where(fn (Builder $q) => $this->applyGroup($q, $definition, $filter, 'filter', 1));
        }

        return $query;
    }

    private function applyGroup(Builder $query, array $definition, mixed $group, string $path, int $depth): void
    {
        if (!is_array($group) || !isset($group['conditions']) || !is_array($group['conditions'])) {
            $this->fail($path, 'A group needs a "conditions" list.');
        }
        if ($depth > self::MAX_DEPTH) {
            $this->fail($path, 'Groups are nested too deeply (max ' . self::MAX_DEPTH . ').');
        }
        $logic = strtolower((string) ($group['logic'] ?? 'and'));
        if (!in_array($logic, ['and', 'or'], true)) {
            $this->fail("{$path}.logic", 'Logic must be "and" or "or".');
        }
        $boolean = $logic === 'or' ? 'or' : 'and';

        foreach (array_values($group['conditions']) as $i => $condition) {
            $childPath = "{$path}.conditions.{$i}";
            if (is_array($condition) && array_key_exists('conditions', $condition)) {
                if ($condition['conditions'] === []) {
                    continue; // an empty group matches everything; skip it
                }
                $query->where(
                    fn (Builder $q) => $this->applyGroup($q, $definition, $condition, $childPath, $depth + 1),
                    null,
                    null,
                    $boolean
                );
                continue;
            }

            if (++$this->conditionCount > self::MAX_CONDITIONS) {
                $this->fail($path, 'Too many conditions (max ' . self::MAX_CONDITIONS . ').');
            }
            $this->applyCondition($query, $definition, $condition, $childPath, $boolean);
        }
    }

    private function applyCondition(Builder $query, array $definition, mixed $condition, string $path, string $boolean): void
    {
        if (!is_array($condition)) {
            $this->fail($path, 'A condition must be an object.');
        }
        $name = (string) ($condition['field'] ?? '');
        $field = $definition['fields'][$name] ?? null;
        if (!$field) {
            $this->fail("{$path}.field", "Unknown field \"{$name}\".");
        }
        $op = (string) ($condition['op'] ?? '');
        if (!in_array($op, $field['operators'] ?? self::OPERATORS[$field['type']], true)) {
            $this->fail("{$path}.op", "Operator \"{$op}\" does not work with {$field['label']}.");
        }
        $value = $condition['value'] ?? null;

        // Field vs field: {"value": {"field": "stylist_price"}}
        if (is_array($value) && array_key_exists('field', $value)) {
            $this->applyFieldComparison($query, $definition, $field, $op, (string) $value['field'], "{$path}.value", $boolean);
            return;
        }

        if (isset($field['apply'])) {
            ($field['apply'])($query, $op, $this->cleanValue($field, $op, $value, "{$path}.value"), $boolean);
            return;
        }

        $column = $this->column($definition, $field);
        $value = $this->cleanValue($field, $op, $value, "{$path}.value");

        match ($field['type']) {
            'number' => $this->applyNumber($query, $column, $op, $value, $boolean),
            'date' => $this->applyDate($query, $column, $op, $value, $boolean),
            'text' => $this->applyText($query, $column, $op, $value, $boolean),
            'bool' => $query->where($column, '=', $op === 'is_true', $boolean),
            'ref', 'enum' => $this->applyIn($query, $column, $op, $value, $boolean),
        };
    }

    private function applyFieldComparison(Builder $query, array $definition, array $field, string $op, string $otherName, string $path, string $boolean): void
    {
        $other = $definition['fields'][$otherName] ?? null;
        if (!$other) {
            $this->fail($path, "Unknown field \"{$otherName}\".");
        }
        if (!in_array($op, self::FIELD_OPERATORS, true)) {
            $this->fail($path, 'Only =, ≠, <, ≤, >, ≥ can compare two fields.');
        }
        if (!$this->isColumnField($field) || !$this->isColumnField($other)
            || $field['type'] !== $other['type'] || !in_array($field['type'], ['number', 'date', 'text'], true)) {
            $this->fail($path, "{$field['label']} cannot be compared with {$other['label']}.");
        }

        $query->whereColumn(
            $this->column($definition, $field),
            self::SQL_OPS[$op],
            $this->column($definition, $other),
            $boolean
        );
    }

    private function applyNumber(Builder $query, string $column, string $op, mixed $value, string $boolean): void
    {
        match ($op) {
            'between' => $query->whereBetween($column, $value, $boolean),
            // "Empty" for a number means missing: no value or 0.
            'empty' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '=', 0), null, null, $boolean),
            'not_empty' => $query->where(fn (Builder $q) => $q->whereNotNull($column)->where($column, '!=', 0), null, null, $boolean),
            default => $query->where($column, self::SQL_OPS[$op], $value, $boolean),
        };
    }

    private function applyDate(Builder $query, string $column, string $op, mixed $value, string $boolean): void
    {
        match ($op) {
            'between' => $query->where(fn (Builder $q) => $q->whereDate($column, '>=', $value[0])->whereDate($column, '<=', $value[1]), null, null, $boolean),
            'empty' => $query->whereNull($column, $boolean),
            'not_empty' => $query->whereNotNull($column, $boolean),
            default => $query->whereDate($column, self::SQL_OPS[$op], $value, $boolean),
        };
    }

    private function applyText(Builder $query, string $column, string $op, mixed $value, string $boolean): void
    {
        $like = fn (string $v) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);

        match ($op) {
            'contains' => $query->where($column, 'like', '%' . $like($value) . '%', $boolean),
            'not_contains' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, 'not like', '%' . $like($value) . '%'), null, null, $boolean),
            'starts_with' => $query->where($column, 'like', $like($value) . '%', $boolean),
            'ends_with' => $query->where($column, 'like', '%' . $like($value), $boolean),
            'eq' => $query->where($column, '=', $value, $boolean),
            'neq' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '!=', $value), null, null, $boolean),
            'empty' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '=', ''), null, null, $boolean),
            'not_empty' => $query->where(fn (Builder $q) => $q->whereNotNull($column)->where($column, '!=', ''), null, null, $boolean),
        };
    }

    private function applyIn(Builder $query, string $column, string $op, mixed $value, string $boolean): void
    {
        match ($op) {
            'in' => $query->whereIn($column, $value, $boolean),
            'not_in' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, $value), null, null, $boolean),
            'empty' => $query->whereNull($column, $boolean),
            'not_empty' => $query->whereNotNull($column, $boolean),
        };
    }

    /**
     * Check and normalise a literal value for the field and operator.
     */
    private function cleanValue(array $field, string $op, mixed $value, string $path): mixed
    {
        if (in_array($op, ['empty', 'not_empty', 'is_true', 'is_false'], true)) {
            return null;
        }

        $type = $field['type'];

        if ($op === 'between') {
            if (!is_array($value) || count($value) !== 2) {
                $this->fail($path, '"Between" needs two values: from and to.');
            }
            $range = [$this->scalar($type, $value[0] ?? null, $path), $this->scalar($type, $value[1] ?? null, $path)];
            if ($range[0] > $range[1]) {
                $range = [$range[1], $range[0]];
            }
            return $range;
        }

        if ($op === 'in' || $op === 'not_in') {
            $values = is_array($value) ? array_values($value) : [$value];
            $values = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
            if ($values === [] || count($values) > 500) {
                $this->fail($path, 'Pick at least one value.');
            }
            if ($type === 'ref') {
                foreach ($values as $v) {
                    if (!is_numeric($v)) {
                        $this->fail($path, 'Choose from the list.');
                    }
                }
                return array_map('intval', $values);
            }
            $allowed = array_map('strval', array_column(($field['options'])(), 'value'));
            foreach ($values as $v) {
                if (!in_array((string) $v, $allowed, true)) {
                    $this->fail($path, "\"{$v}\" is not a valid choice.");
                }
            }
            return $values;
        }

        return $this->scalar($type, $value, $path);
    }

    private function scalar(string $type, mixed $value, string $path): mixed
    {
        if ($type === 'number' || $type === 'count') {
            if (!is_numeric($value)) {
                $this->fail($path, 'Enter a number.');
            }
            return $type === 'count' ? (int) $value : (float) $value;
        }
        if ($type === 'date') {
            try {
                return Carbon::createFromFormat('Y-m-d', (string) $value)->toDateString();
            } catch (\Throwable) {
                $this->fail($path, 'Enter a date (YYYY-MM-DD).');
            }
        }
        if (!is_scalar($value) || trim((string) $value) === '') {
            $this->fail($path, 'Enter a value.');
        }
        return mb_substr(trim((string) $value), 0, 255);
    }

    private function isColumnField(array $field): bool
    {
        return !isset($field['apply']);
    }

    private function column(array $definition, array $field): string
    {
        return $definition['table'] . '.' . $field['column'];
    }

    private function definition(string $entity): array
    {
        $definition = $this->definitions()[$entity] ?? null;
        if (!$definition) {
            throw ValidationException::withMessages(['entity' => "Unknown data type \"{$entity}\"."]);
        }
        return $definition;
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }

    /* ========================================= */
    /* ALLOWLIST                                 */
    /* ========================================= */

    private function definitions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $col = fn (string $label, string $type, ?string $column = null, array $extra = []) => ['label' => $label, 'type' => $type, 'column' => $column] + $extra;
        $count = fn (string $label, string $relation) => [
            'label' => $label,
            'type' => 'count',
            'apply' => fn (Builder $q, string $op, int $n, string $boolean) => $q->has($relation, self::SQL_OPS[$op], $n, $boolean),
        ];
        $brands = fn () => Brand::query()->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all();
        $categories = fn () => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all();

        $placeholder = '%' . Product::PLACEHOLDER_IMAGE_PATH . '%';
        $realPhoto = fn (Builder $images) => $images
            ->where('name', 'not like', $placeholder)
            ->where(fn (Builder $s) => $s->whereNull('review_status')->orWhere('review_status', '!=', 'hidden'))
            ->where(fn (Builder $u) => $u->whereNull('url')->orWhere('url', 'not like', $placeholder));

        $cmp = fn (string $a, string $b) => ['logic' => 'and', 'conditions' => [['field' => $a, 'op' => 'lt', 'value' => ['field' => $b]]]];
        $one = fn (string $field, string $op, mixed $value = null) => ['logic' => 'and', 'conditions' => [['field' => $field, 'op' => $op, 'value' => $value]]];

        return $cache = [
            'products' => [
                'label' => 'Products',
                'table' => 'products',
                'query' => fn () => Product::query()->with(['brand:id,name', 'category:id,name'])->withCount('images'),
                'default_sort' => ['field' => 'id', 'dir' => 'desc'],
                'fields' => [
                    'name' => $col('Name', 'text', 'name'),
                    'brand_id' => $col('Brand', 'ref', 'brand_id', ['options' => $brands]),
                    'category_id' => $col('Category', 'ref', 'category_id', ['options' => $categories]),
                    'price' => $col('Price', 'number', 'price'),
                    'stylist_price' => $col('Stylist price', 'number', 'stylist_price'),
                    'cost_price' => $col('Cost price', 'number', 'cost_price'),
                    'compare_at_price' => $col('Old price (compare at)', 'number', 'compare_at_price'),
                    'quantity' => $col('Stock', 'number', 'quantity'),
                    'has_photo' => [
                        'label' => 'Has a real photo',
                        'type' => 'bool',
                        'apply' => fn (Builder $q, string $op, $v, string $boolean) => $op === 'is_true'
                            ? $q->whereHas('images', $realPhoto, '>=', 1, $boolean)
                            : $q->whereDoesntHave('images', $realPhoto, $boolean),
                    ],
                    'images_count' => $count('Number of photos', 'images'),
                    'is_active' => $col('Active', 'bool', 'is_active'),
                    'featured' => $col('Featured', 'bool', 'featured'),
                    'visible_to_guests' => $col('Shown to guests', 'bool', 'visible_to_guests'),
                    'visible_to_customers' => $col('Shown to customers', 'bool', 'visible_to_customers'),
                    'visible_to_stylists' => $col('Shown to stylists', 'bool', 'visible_to_stylists'),
                    'sku' => $col('SKU', 'text', 'sku'),
                    'ean' => $col('EAN / barcode', 'text', 'ean'),
                    'manufacturer_code' => $col('Manufacturer code', 'text', 'manufacturer_code'),
                    'description' => $col('Description', 'text', 'description'),
                    'variant_name' => $col('Variant', 'text', 'variant_name'),
                    'shade_name' => $col('Shade name', 'text', 'shade_name'),
                    'shade_code' => $col('Shade code', 'text', 'shade_code'),
                    'size_value' => $col('Size', 'number', 'size_value'),
                    'id' => $col('ID', 'number', 'id'),
                    'created_at' => $col('Created', 'date', 'created_at'),
                    'updated_at' => $col('Last changed', 'date', 'updated_at'),
                ],
                'columns' => [
                    ['key' => 'id', 'label' => 'ID', 'type' => 'number'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'brand', 'label' => 'Brand', 'type' => 'text'],
                    ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                    ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'sort' => 'price'],
                    ['key' => 'stylist_price', 'label' => 'Stylist', 'type' => 'money', 'sort' => 'stylist_price'],
                    ['key' => 'cost_price', 'label' => 'Cost', 'type' => 'money', 'sort' => 'cost_price'],
                    ['key' => 'quantity', 'label' => 'Stock', 'type' => 'number', 'sort' => 'quantity'],
                    ['key' => 'images_count', 'label' => 'Photos', 'type' => 'number'],
                    ['key' => 'is_active', 'label' => 'Active', 'type' => 'bool'],
                ],
                'row' => fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'brand' => $p->brand?->name,
                    'category' => $p->category?->name,
                    'price' => $p->price !== null ? (float) $p->price : null,
                    'stylist_price' => $p->stylist_price !== null ? (float) $p->stylist_price : null,
                    'cost_price' => $p->cost_price !== null ? (float) $p->cost_price : null,
                    'quantity' => $p->quantity,
                    'images_count' => (int) $p->images_count,
                    'is_active' => (bool) $p->is_active,
                ],
                'presets' => [
                    ['key' => 'price_below_stylist', 'label' => 'Price below stylist price', 'filter' => $cmp('price', 'stylist_price')],
                    ['key' => 'out_of_stock', 'label' => 'Out of stock', 'filter' => $one('quantity', 'lte', 0)],
                    ['key' => 'low_stock', 'label' => 'Low stock (1–3)', 'filter' => $one('quantity', 'between', [1, 3])],
                    ['key' => 'no_photo', 'label' => 'No photo', 'filter' => $one('has_photo', 'is_false')],
                    ['key' => 'stylist_missing', 'label' => 'Stylist price missing', 'filter' => $one('stylist_price', 'empty')],
                    ['key' => 'price_missing', 'label' => 'Price missing', 'filter' => $one('price', 'empty')],
                    ['key' => 'below_cost', 'label' => 'Price below cost', 'filter' => $cmp('price', 'cost_price')],
                    ['key' => 'stylist_below_cost', 'label' => 'Stylist price below cost', 'filter' => $cmp('stylist_price', 'cost_price')],
                    ['key' => 'in_stock_no_photo', 'label' => 'In stock but no photo', 'filter' => ['logic' => 'and', 'conditions' => [
                        ['field' => 'quantity', 'op' => 'gt', 'value' => 0],
                        ['field' => 'has_photo', 'op' => 'is_false'],
                    ]]],
                ],
            ],

            'orders' => [
                'label' => 'Orders',
                'table' => 'orders',
                'query' => fn () => Order::query()->with(['user:id,first_name,last_name,email', 'info'])->withCount('items'),
                'default_sort' => ['field' => 'created_at', 'dir' => 'desc'],
                'fields' => [
                    'id' => $col('Order #', 'number', 'id'),
                    'status' => $col('Status', 'enum', 'status', ['options' => fn () => [
                        ['value' => Order::STATUS_PENDING, 'label' => 'Pending'],
                        ['value' => Order::STATUS_PAID, 'label' => 'Paid'],
                        ['value' => Order::STATUS_SHIPPED, 'label' => 'Shipped'],
                        ['value' => Order::STATUS_CANCELLED, 'label' => 'Cancelled'],
                    ]]),
                    'total' => $col('Total', 'number', 'total'),
                    'discount' => $col('Discount', 'number', 'discount'),
                    'shipping' => $col('Shipping', 'number', 'shipping'),
                    'store_credit_used' => $col('Store credit used', 'number', 'store_credit_used'),
                    'referral_commission' => $col('Referral commission', 'number', 'referral_commission'),
                    'items_count' => $count('Number of lines', 'items'),
                    'customer' => [
                        'label' => 'Customer name / email / phone',
                        'type' => 'text',
                        'operators' => ['contains', 'not_contains'],
                        'apply' => function (Builder $q, string $op, $v, string $boolean) {
                            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $v) . '%';
                            $match = fn (Builder $r) => $r->where(fn (Builder $w) => $w
                                ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                                ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like));
                            $q->where(fn (Builder $w) => $op === 'contains'
                                ? $w->whereHas('user', $match)->orWhereHas('info', $match)
                                : $w->whereDoesntHave('user', $match)->whereDoesntHave('info', $match),
                                null, null, $boolean);
                        },
                    ],
                    'payment_status' => $col('Payment status', 'text', 'payment_status'),
                    'payment_method' => $col('Payment method', 'text', 'payment_method'),
                    'coupon_code' => $col('Coupon code', 'text', 'coupon_code'),
                    'tracking_number' => $col('Tracking number', 'text', 'tracking_number'),
                    'message' => $col('Customer note', 'text', 'message'),
                    'created_at' => $col('Placed on', 'date', 'created_at'),
                    'updated_at' => $col('Last changed', 'date', 'updated_at'),
                ],
                'columns' => [
                    ['key' => 'id', 'label' => '#', 'type' => 'number'],
                    ['key' => 'created_at', 'label' => 'Placed', 'type' => 'date', 'sort' => 'created_at'],
                    ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'sort' => 'status'],
                    ['key' => 'items_count', 'label' => 'Lines', 'type' => 'number'],
                    ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'sort' => 'discount'],
                    ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sort' => 'total'],
                    ['key' => 'payment_method', 'label' => 'Payment', 'type' => 'text'],
                ],
                'row' => fn (Order $o) => [
                    'id' => $o->id,
                    'created_at' => $o->created_at?->toDateTimeString(),
                    'customer' => trim(($o->user?->first_name ?? $o->info?->first_name ?? '') . ' ' . ($o->user?->last_name ?? $o->info?->last_name ?? ''))
                        ?: ($o->user?->email ?? $o->info?->email),
                    'status' => [Order::STATUS_PENDING => 'Pending', Order::STATUS_PAID => 'Paid', Order::STATUS_SHIPPED => 'Shipped', Order::STATUS_CANCELLED => 'Cancelled'][(int) $o->status] ?? (string) $o->status,
                    'items_count' => (int) $o->items_count,
                    'discount' => (float) $o->discount,
                    'total' => (float) $o->total,
                    'payment_method' => $o->payment_method,
                ],
                'presets' => [
                    ['key' => 'pending', 'label' => 'Pending', 'filter' => $one('status', 'in', [Order::STATUS_PENDING])],
                    ['key' => 'cancelled', 'label' => 'Cancelled', 'filter' => $one('status', 'in', [Order::STATUS_CANCELLED])],
                    ['key' => 'with_coupon', 'label' => 'Used a coupon', 'filter' => $one('coupon_code', 'not_empty')],
                    ['key' => 'big', 'label' => 'Total 5000+', 'filter' => $one('total', 'gte', 5000)],
                    ['key' => 'paid_not_shipped', 'label' => 'Paid, no tracking number', 'filter' => ['logic' => 'and', 'conditions' => [
                        ['field' => 'status', 'op' => 'in', 'value' => [Order::STATUS_PAID]],
                        ['field' => 'tracking_number', 'op' => 'empty'],
                    ]]],
                ],
            ],

            'customers' => [
                'label' => 'Customers',
                'table' => 'users',
                'query' => fn () => User::query()->withCount('orders'),
                'default_sort' => ['field' => 'created_at', 'dir' => 'desc'],
                'fields' => [
                    'first_name' => $col('First name', 'text', 'first_name'),
                    'last_name' => $col('Last name', 'text', 'last_name'),
                    'email' => $col('Email', 'text', 'email'),
                    'phone' => $col('Phone', 'text', 'phone'),
                    'city' => $col('City', 'text', 'city'),
                    'address' => $col('Address', 'text', 'address'),
                    'postcode' => $col('Postcode', 'text', 'postcode'),
                    'role' => $col('Account type', 'enum', 'role', ['options' => fn () => [
                        ['value' => User::ROLE_USER, 'label' => 'Customer'],
                        ['value' => User::ROLE_STYLIST, 'label' => 'Stylist'],
                        ['value' => User::ROLE_ADMIN, 'label' => 'Admin'],
                    ]]),
                    'orders_count' => $count('Number of orders', 'orders'),
                    'email_verified_at' => $col('Email confirmed on', 'date', 'email_verified_at'),
                    'email_opt_out_at' => $col('Unsubscribed on', 'date', 'email_opt_out_at'),
                    'created_at' => $col('Signed up', 'date', 'created_at'),
                    'id' => $col('ID', 'number', 'id'),
                ],
                'columns' => [
                    ['key' => 'id', 'label' => 'ID', 'type' => 'number'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'sort' => 'email'],
                    ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text', 'sort' => 'city'],
                    ['key' => 'role', 'label' => 'Type', 'type' => 'text'],
                    ['key' => 'orders_count', 'label' => 'Orders', 'type' => 'number'],
                    ['key' => 'created_at', 'label' => 'Signed up', 'type' => 'date', 'sort' => 'created_at'],
                ],
                'row' => fn (User $u) => [
                    'id' => $u->id,
                    'name' => trim($u->first_name . ' ' . $u->last_name),
                    'email' => $u->email,
                    'phone' => $u->phone,
                    'city' => $u->city,
                    'role' => [User::ROLE_USER => 'Customer', User::ROLE_STYLIST => 'Stylist', User::ROLE_ADMIN => 'Admin'][(int) $u->role] ?? (string) $u->role,
                    'orders_count' => (int) $u->orders_count,
                    'created_at' => $u->created_at?->toDateTimeString(),
                ],
                'presets' => [
                    ['key' => 'never_ordered', 'label' => 'Never ordered', 'filter' => $one('orders_count', 'eq', 0)],
                    ['key' => 'stylists', 'label' => 'Stylists', 'filter' => $one('role', 'in', [User::ROLE_STYLIST])],
                    ['key' => 'repeat', 'label' => '3+ orders', 'filter' => $one('orders_count', 'gte', 3)],
                    ['key' => 'no_phone', 'label' => 'No phone', 'filter' => $one('phone', 'empty')],
                ],
            ],
        ];
    }
}
