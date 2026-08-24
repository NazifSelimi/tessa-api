<?php

namespace Tests\Unit;

use App\Support\ProductCatalogGuidance;
use Tests\TestCase;

class ProductCatalogGuidanceTest extends TestCase
{
    public function test_it_marks_technical_categories_as_professional_only(): void
    {
        $guidance = ProductCatalogGuidance::forProduct('Bleach and De Color', 'Blue bleaching powder');

        $this->assertSame('professional', $guidance['audience']);
        $this->assertTrue($guidance['professionalOnly']);
        $this->assertSame(['Fanola Oxy'], $guidance['compatibleWith']);
    }

    public function test_it_pairs_oro_therapy_color_with_its_own_activator(): void
    {
        $guidance = ProductCatalogGuidance::forProduct('Hair Color', 'Oro Therapy Color Keratin 7.3');

        $this->assertSame(['Oro Therapy Gold Activator'], $guidance['compatibleWith']);
    }

    public function test_it_keeps_home_care_products_visible_to_consumers(): void
    {
        $guidance = ProductCatalogGuidance::forProduct('Shampoo', 'Wonder Curl Shampoo');

        $this->assertSame('consumer', $guidance['audience']);
        $this->assertFalse($guidance['professionalOnly']);
        $this->assertSame([], $guidance['compatibleWith']);
    }
}
