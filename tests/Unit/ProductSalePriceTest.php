<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Sale;
use Tests\TestCase;

class ProductSalePriceTest extends TestCase
{
    private function product(float $price, float $stylistPrice, ?float $salePrice): Product
    {
        $product = new Product(['price' => $price, 'stylist_price' => $stylistPrice]);
        $product->setRelation('sale', $salePrice === null ? null : new Sale([
            'sale_price' => $salePrice,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]));

        return $product;
    }

    /** @test */
    public function a_sale_lowers_the_retail_price()
    {
        $this->assertSame(800.0, $this->product(1000, 600, 800)->resolvePrice(false));
    }

    /** @test */
    public function a_sale_never_raises_the_salon_price()
    {
        $this->assertSame(600.0, $this->product(1000, 600, 800)->resolvePrice(true));
    }

    /** @test */
    public function a_sale_below_the_salon_price_applies_to_salons()
    {
        $this->assertSame(500.0, $this->product(1000, 600, 500)->resolvePrice(true));
    }

    /** @test */
    public function a_sale_does_not_make_an_unpriced_row_purchasable()
    {
        $this->assertSame(0.0, $this->product(1000, 0, 500)->resolvePrice(true));
    }

    /** @test */
    public function an_expired_sale_is_ignored()
    {
        $product = $this->product(1000, 600, 500);
        $product->sale->end_date = now()->subHour();

        $this->assertSame(1000.0, $product->resolvePrice(false));
    }
}
