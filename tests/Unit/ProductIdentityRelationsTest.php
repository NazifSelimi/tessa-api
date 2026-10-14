<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductLine;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tests\TestCase;

class ProductIdentityRelationsTest extends TestCase
{
    public function test_product_identity_relationships_are_additive(): void
    {
        $this->assertInstanceOf(BelongsTo::class, (new Product())->productFamily());
        $this->assertInstanceOf(BelongsTo::class, (new ProductLine())->brand());
        $this->assertInstanceOf(HasMany::class, (new ProductLine())->productFamilies());
        $this->assertInstanceOf(BelongsTo::class, (new ProductFamily())->brand());
        $this->assertInstanceOf(BelongsTo::class, (new ProductFamily())->category());
        $this->assertInstanceOf(BelongsTo::class, (new ProductFamily())->productLine());
        $this->assertInstanceOf(HasMany::class, (new ProductFamily())->products());
        $this->assertInstanceOf(MorphMany::class, (new ProductFamily())->images());
        $this->assertInstanceOf(HasMany::class, (new Brand())->productLines());
        $this->assertInstanceOf(HasMany::class, (new Brand())->productFamilies());
        $this->assertInstanceOf(HasMany::class, (new Category())->productFamilies());
    }
}
