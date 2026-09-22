<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class CatalogImageCandidateController extends Controller
{
    public function index(Request $request)
    {
        $audit = $this->latestAudit();
        abort_unless($audit, 404, 'No image candidate audit is available.');
        $rows = collect(json_decode(File::get($audit . '/image_replacement_manifest.json'), true)['rows'] ?? [])
            ->filter(fn (array $row) => filled($row['source_image_url']))
            ->values()
            ->map(fn (array $row) => array_merge($row, [
                'preview_url' => url('/api/v1/admin/catalog-image-candidates/' . $row['product_id'] . '/preview?rank=' . $row['candidate_rank']),
            ]));

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
