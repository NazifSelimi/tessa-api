<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CatalogImageCandidateController extends Controller
{
    public function health(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:missing,needs_review,legacy,approved'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()->with(['brand', 'images'])->get();
        $rows = $products->map(function (Product $product): array {
            $images = $product->images;
            $primary = $images->firstWhere('variant', 'card')
                ?? $images->firstWhere('variant', 'detail')
                ?? $images->first();
            $statuses = $images->pluck('review_status')->filter()->unique();

            $status = match (true) {
                $images->isEmpty() => 'missing',
                $statuses->contains('needs_review') => 'needs_review',
                $statuses->contains('approved') => 'approved',
                default => 'legacy',
            };

            return [
                'id' => (string) $product->id,
                'name' => $product->name,
                'brand' => $product->brand?->name,
                'status' => $status,
                'imageUrl' => $primary?->url ?: $primary?->publicUrl(),
                'imageCount' => $images->count(),
            ];
        });

        $counts = collect(['missing', 'needs_review', 'legacy', 'approved'])
            ->mapWithKeys(fn (string $status) => [$status => $rows->where('status', $status)->count()]);
        $status = $data['status'] ?? null;
        $limit = $data['limit'] ?? 24;

        return response()->json([
            'summary' => [
                'total' => $products->count(),
                'with_image' => $products->count() - $counts['missing'],
                ...$counts->all(),
            ],
            'products' => $rows
                ->when($status, fn ($collection) => $collection->where('status', $status))
                ->sortBy('name')
                ->take($limit)
                ->values(),
        ]);
    }

    public function index(Request $request)
    {
        $audit = $this->latestAudit();
        abort_unless($audit, 404, 'No image candidate audit is available.');
        $reviews = is_file($audit . '/admin_reviews.json') ? json_decode(File::get($audit . '/admin_reviews.json'), true) : [];
        $rows = collect(json_decode(File::get($audit . '/image_replacement_manifest.json'), true)['rows'] ?? [])
            ->values()
            ->map(function (array $row) use ($reviews) {
                $current = (string) ($row['current_primary_image'] ?? '');
                return array_merge($row, [
                    'current_image_url' => Str::startsWith($current, ['http://', 'https://']) ? $current : ($current !== '' ? url('/storage/images/' . ltrim($current, '/')) : null),
                    'preview_url' => url('/api/v1/admin/catalog-image-candidates/' . $row['product_id'] . '/preview?rank=' . $row['candidate_rank']),
                    'admin_review' => $reviews[$row['product_id'] . ':' . $row['candidate_rank']] ?? null,
                ]);
            });

        return response()->json(['audit' => basename($audit), 'candidates' => $rows]);
    }

    public function approve(Request $request, int $productId)
    {
        $audit = $this->latestAudit();
        abort_unless($audit, 404, 'No image candidate audit is available.');
        $rank = max(1, (int) $request->input('rank', 1));
        $path = $audit . '/admin_reviews.json';
        $reviews = is_file($path) ? json_decode(File::get($path), true) : [];
        $reviews[$productId . ':' . $rank] = ['status' => 'approved', 'approved_at' => now()->toIso8601String(), 'approved_by' => $request->user()->id];
        File::put($path, json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json(['message' => 'Candidate marked approved for the next controlled installation.']);
    }

    public function preview(Request $request, int $productId)
    {
        $audit = $this->latestAudit();
        abort_unless($audit, 404, 'No image candidate audit is available.');
        $rank = max(1, (int) $request->query('rank', 1));
        $rows = json_decode(File::get($audit . '/image_replacement_manifest.json'), true)['rows'] ?? [];
        $candidate = collect($rows)->first(fn (array $row) => (int) $row['product_id'] === $productId && (int) $row['candidate_rank'] === $rank);
        $path = $candidate['downloaded_path'] ?? null;
        abort_unless($path && is_file($audit . '/' . $path), 404, 'Candidate source image is unavailable.');

        return response()->file($audit . '/' . $path);
    }

    private function latestAudit(): ?string
    {
        $directories = glob(storage_path('app/catalog-audits/*-image-replacement')) ?: [];
        rsort($directories, SORT_STRING);

        return collect($directories)->first(fn (string $directory) => is_file($directory . '/image_replacement_manifest.json'));
    }
}
