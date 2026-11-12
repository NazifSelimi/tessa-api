<?php

namespace App\Services;

final class CatalogImageInstallationGate
{
    /** @param array<string, mixed> $candidate @param array<string, mixed>|null $review */
    public function allows(array $candidate, ?array $review): bool
    {
        if (($review['status'] ?? null) !== 'approved'
            || (int) ($candidate['candidate_rank'] ?? 0) !== 1
            || ($candidate['replacement_status'] ?? null) !== 'approved_exact'
            || blank($candidate['downloaded_path'] ?? null)
            || blank($candidate['source_image_url'] ?? null)) {
            return false;
        }

        foreach (['exact_product_match', 'exact_package_match', 'exact_size_match'] as $field) {
            if (($candidate[$field] ?? null) !== 'verified') {
                return false;
            }
        }

        foreach (['exact_range_match', 'exact_shade_match'] as $field) {
            if (isset($candidate[$field]) && ! in_array($candidate[$field], ['verified', 'not_applicable'], true)) {
                return false;
            }
        }

        return true;
    }
}
