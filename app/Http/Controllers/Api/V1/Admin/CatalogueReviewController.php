<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A deliberately review-first bridge between the supplier price list and the
 * catalogue. It never performs a bulk import: each pairing or new product is
 * a separate, visible admin decision.
 */
class CatalogueReviewController extends Controller
{
    private const AUDIT_PATH = 'catalogue-review/supplier-comparison.json';
    private const DECISIONS_PATH = 'catalogue-review/decisions.json';

    public function __construct(protected ProductService $productService) {}

    public function index(Request $request)
    {
        $rows = $this->rows();
        $decisions = $this->decisions();
        $status = $request->string('status')->toString();
        $search = Str::lower($request->string('search')->toString());
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        $summary = [
            'total' => count($rows),
            'confirmed' => 0,
            'conflicts' => 0,
            'ambiguous' => 0,
            'missing' => 0,
            'resolved' => count($decisions),
        ];

        $mapped = collect($rows)->map(function (array $row) use ($decisions, &$summary) {
            $reviewStatus = match ($row['status'] ?? '') {
                'exact', 'likely' => 'confirmed',
                'possible_size_or_legacy_variant' => 'conflict',
                default => $row['status'] ?? 'missing',
            };
            $summary[match ($reviewStatus) {
                'confirmed' => 'confirmed',
                'conflict' => 'conflicts',
                'ambiguous' => 'ambiguous',
                default => 'missing',
            }]++;

            $key = $this->key($row);
            return [
                ...$row,
                'key' => $key,
                'review_status' => $reviewStatus,
                'decision' => $decisions[$key] ?? null,
            ];
        });

        $filtered = $mapped->filter(function (array $row) use ($status, $search) {
            if ($status === 'resolved' && empty($row['decision'])) return false;
            if ($status && $status !== 'resolved' && $row['review_status'] !== $status) return false;
            if (!$search) return true;

            return Str::contains(Str::lower(implode(' ', [
                $row['code'] ?? '', $row['description'] ?? '', $row['candidate_name'] ?? '',
                $row['candidate_brand'] ?? '', $row['candidate_category'] ?? '',
            ])), $search);
        })->values();

        return ApiResponse::ok(
            $filtered->slice(($page - 1) * $perPage, $perPage)->values(),
            200,
            [
                'summary' => $summary,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $filtered->count(),
                'last_page' => max(1, (int) ceil($filtered->count() / $perPage)),
            ]
        );
    }

    public function confirmMatch(string $itemKey)
    {
        $row = $this->find($itemKey);
        abort_unless(in_array($row['status'] ?? '', ['exact', 'likely'], true), 422, 'Only confirmed matches can be paired automatically.');

        $product = Product::findOrFail($row['candidate_id']);
        $product->forceFill(['manufacturer_code' => (string) $row['code']])->save();
        $this->saveDecision($itemKey, ['type' => 'matched', 'product_id' => $product->id, 'at' => now()->toIso8601String()]);

        return ApiResponse::ok(['product_id' => $product->id], 200, [], 'Supplier code paired with the existing product.');
    }

    public function createMissing(Request $request, string $itemKey)
    {
        $row = $this->find($itemKey);
        abort_unless(($row['status'] ?? '') === 'missing', 422, 'Only a reviewed missing supplier item can be added here.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stylist_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'brand_id' => ['required', 'exists:brands,id'],
            'category_id' => ['required', 'exists:categories,id'],
            'stylist_only' => ['nullable', 'boolean'],
        ]);

        $product = $this->productService->create([
            ...$validated,
            'stylist_price' => $validated['stylist_price'] ?? ($validated['price'] * 0.9),
        ]);
        $product->forceFill(['manufacturer_code' => (string) $row['code']])->save();
        $this->saveDecision($itemKey, ['type' => 'created', 'product_id' => $product->id, 'at' => now()->toIso8601String()]);

        return ApiResponse::ok(['product_id' => $product->id], 201, [], 'Reviewed supplier product added with zero-risk default stock.');
    }

    public function dismiss(string $itemKey)
    {
        $this->find($itemKey);
        $this->saveDecision($itemKey, ['type' => 'not_sold', 'at' => now()->toIso8601String()]);
        return ApiResponse::ok(null, 200, [], 'Marked as not currently sold.');
    }

    private function rows(): array
    {
        abort_unless(Storage::exists(self::AUDIT_PATH), 503, 'Catalogue review data is not installed yet.');
        return json_decode(Storage::get(self::AUDIT_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    private function decisions(): array
    {
        return Storage::exists(self::DECISIONS_PATH)
            ? json_decode(Storage::get(self::DECISIONS_PATH), true, 512, JSON_THROW_ON_ERROR)
            : [];
    }

    private function saveDecision(string $itemKey, array $decision): void
    {
        $decisions = $this->decisions();
        $decisions[$itemKey] = $decision;
        Storage::put(self::DECISIONS_PATH, json_encode($decisions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function find(string $itemKey): array
    {
        return collect($this->rows())->first(fn (array $row) => hash_equals($this->key($row), $itemKey))
            ?? abort(404, 'Catalogue review item not found.');
    }

    private function key(array $row): string
    {
        return sha1(implode('|', [$row['page'] ?? '', $row['code'] ?? '', $row['description'] ?? '']));
    }
}
