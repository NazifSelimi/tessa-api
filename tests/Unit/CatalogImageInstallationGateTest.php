<?php

namespace Tests\Unit;

use App\Services\CatalogImageInstallationGate;
use PHPUnit\Framework\TestCase;

class CatalogImageInstallationGateTest extends TestCase
{
    public function test_it_allows_only_an_explicitly_approved_exact_match(): void
    {
        $candidate = [
            'candidate_rank' => 1,
            'replacement_status' => 'approved_exact',
            'downloaded_path' => 'source-images/product.webp',
            'source_image_url' => 'https://manufacturer.example/product.webp',
            'exact_product_match' => 'verified',
            'exact_package_match' => 'verified',
            'exact_size_match' => 'verified',
            'exact_range_match' => 'verified',
            'exact_shade_match' => 'not_applicable',
        ];

        $this->assertTrue((new CatalogImageInstallationGate())->allows($candidate, ['status' => 'approved']));
    }

    public function test_it_rejects_an_unreviewed_or_inexact_candidate(): void
    {
        $candidate = [
            'candidate_rank' => 1,
            'replacement_status' => 'needs_review',
            'downloaded_path' => 'source-images/product.webp',
            'source_image_url' => 'https://manufacturer.example/product.webp',
            'exact_product_match' => 'verified',
            'exact_package_match' => 'not_reviewed',
            'exact_size_match' => 'verified',
            'exact_shade_match' => 'not_reviewed',
        ];

        $gate = new CatalogImageInstallationGate();

        $this->assertFalse($gate->allows($candidate, ['status' => 'approved']));
        $this->assertFalse($gate->allows(array_merge($candidate, ['replacement_status' => 'approved_exact']), null));
    }
}
