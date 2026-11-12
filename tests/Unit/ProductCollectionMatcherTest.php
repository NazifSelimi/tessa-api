<?php

namespace Tests\Unit;

use App\Support\ProductCollectionMatcher;
use Tests\TestCase;

class ProductCollectionMatcherTest extends TestCase
{
    public function test_it_confirms_explicit_fanola_no_yellow_maintenance_products(): void
    {
        $mapping = ProductCollectionMatcher::matchBlondeAndTone(
            'Fanola',
            'Shampoo',
            'No Yellow Shampoo'
        );

        $this->assertSame('blonde-and-tone', $mapping['collection_slug']);
        $this->assertSame(ProductCollectionMatcher::STATUS_CONFIRMED, $mapping['mapping_status']);
    }

    public function test_it_flags_ambiguous_blonde_colour_for_review(): void
    {
        $mapping = ProductCollectionMatcher::matchBlondeAndTone(
            'Fanola',
            'Hair Color',
            'Professional Blonde 8.1'
        );

        $this->assertSame(ProductCollectionMatcher::STATUS_UNCERTAIN, $mapping['mapping_status']);
    }

    public function test_it_does_not_guess_non_fanola_family_products(): void
    {
        $mapping = ProductCollectionMatcher::matchBlondeAndTone(
            'Rr Line',
            'Shampoo',
            'No Yellow Shampoo'
        );

        $this->assertNull($mapping);
    }
}
