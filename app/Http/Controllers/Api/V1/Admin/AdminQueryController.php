<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AdminQuery\AdminQueryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin query tool: filter products, orders and customers with field/operator/value
 * conditions (see AdminQueryService), and keep named queries to open again.
 */
class AdminQueryController extends Controller
{
    private const SAVED_KEY = 'admin_saved_queries';
    private const MAX_SAVED = 100;

    public function __construct(protected AdminQueryService $queries) {}

    public function schema()
    {
        return ApiResponse::ok($this->queries->schema());
    }

    public function run(Request $request)
    {
        $validated = $request->validate([
            'entity' => ['required', 'string', Rule::in($this->queries->entities())],
            'filter' => ['nullable', 'array'],
            'sort' => ['nullable', 'array'],
            'sort.field' => ['nullable', 'string', 'max:64'],
            'sort.dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . AdminQueryService::MAX_PER_PAGE],
        ]);

        $result = $this->queries->run(
            $validated['entity'],
            $validated['filter'] ?? null,
            $validated['sort'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
        );

        return ApiResponse::ok($result['rows'], 200, $result['meta']);
    }

    public function saved()
    {
        return ApiResponse::ok($this->savedList());
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'id' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'entity' => ['required', 'string', Rule::in($this->queries->entities())],
            'filter' => ['required', 'array'],
            'sort' => ['nullable', 'array'],
        ]);

        // Reject a query that would not run, so saved queries always open cleanly.
        $this->queries->build($validated['entity'], $validated['filter']);

        $list = $this->savedList();
        $id = $validated['id'] ?? null;
        $entry = [
            'id' => $id ?: (string) Str::ulid(),
            'name' => trim($validated['name']),
            'entity' => $validated['entity'],
            'filter' => $validated['filter'],
            'sort' => $validated['sort'] ?? null,
            'updated_at' => now()->toIso8601String(),
            'updated_by' => trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')) ?: $request->user()->email,
        ];

        $index = $id ? collect($list)->search(fn ($q) => $q['id'] === $id) : false;
        if ($index !== false) {
            $list[$index] = $entry;
        } else {
            if (count($list) >= self::MAX_SAVED) {
                return ApiResponse::error('Too many saved queries; delete some first.', 422);
            }
            array_unshift($list, $entry);
        }

        Setting::put(self::SAVED_KEY, json_encode(array_values($list)));

        return ApiResponse::ok($entry, $index !== false ? 200 : 201);
    }

    public function destroy(string $id)
    {
        $list = array_values(array_filter($this->savedList(), fn ($q) => $q['id'] !== $id));
        Setting::put(self::SAVED_KEY, json_encode($list));

        return ApiResponse::ok(null);
    }

    private function savedList(): array
    {
        $list = json_decode((string) Setting::get(self::SAVED_KEY, '[]'), true);

        return is_array($list) ? array_values(array_filter($list, fn ($q) => is_array($q) && isset($q['id']))) : [];
    }
}
