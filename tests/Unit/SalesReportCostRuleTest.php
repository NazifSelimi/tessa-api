<?php

namespace Tests\Unit;

use App\Services\PricingService;
use App\Services\SalesReportService;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/** Database-free: reports use the checkout cost rule, and the routes exist. */
class SalesReportCostRuleTest extends TestCase
{
    private function cost(array $row): array
    {
        $service = new SalesReportService($this->app->make(PricingService::class));
        $method = new ReflectionMethod($service, 'unitCost');

        return $method->invoke($service, (object) [
            'id' => 1, 'price' => '1000.00', 'cost_price' => null,
            'category_name' => 'Shampoo', 'cost_percentage' => null,
            ...$row,
        ], ['costPercentage' => 65.0, 'minMarginPercentage' => 15.0]);
    }

    #[Test]
    public function a_real_purchase_cost_wins()
    {
        $this->assertSame([420.0, 'real'], $this->cost(['cost_price' => '420.00', 'cost_percentage' => '40.00']));
    }

    #[Test]
    public function the_category_percentage_comes_before_the_shop_wide_one()
    {
        $this->assertSame([400.0, 'estimated'], $this->cost(['cost_percentage' => '40.00']));
        $this->assertSame([650.0, 'estimated'], $this->cost([]));
        $this->assertSame([650.0, 'estimated'], $this->cost(['category_name' => null]));
    }

    #[Test]
    public function no_price_and_no_cost_or_a_deleted_product_is_unknown()
    {
        $this->assertSame([null, 'unknown'], $this->cost(['price' => '0.00']));
        $this->assertSame([null, 'unknown'], $this->cost(['id' => null]));
    }

    #[Test]
    public function the_report_routes_are_registered()
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

        foreach (['overview', 'summary', 'slow-movers'] as $path) {
            $this->assertTrue($uris->contains(fn ($uri) => str_ends_with($uri, "admin/reports/{$path}")), $path);
        }
    }
}
