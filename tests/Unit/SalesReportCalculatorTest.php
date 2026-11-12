<?php

namespace Tests\Unit;

use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SalesReportCalculator as Calc;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Database-free: the report rules on hand-made rows. */
class SalesReportCalculatorTest extends TestCase
{
    private CarbonImmutable $start;
    private CarbonImmutable $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = CarbonImmutable::parse('2026-11-01 00:00:00');
        $this->end = CarbonImmutable::parse('2026-11-09 23:59:59');
    }

    private function order(array $overrides = []): array
    {
        return [
            'id' => 1, 'createdAt' => '2026-11-03 10:00:00', 'status' => 1,
            'total' => 1000, 'discount' => 0, 'shipping' => 0, 'storeCreditUsed' => 0,
            'referralCommission' => 0, 'couponCode' => null,
            'customerKey' => 'ana@example.com', 'customerName' => 'Ана Петрова',
            'customerEmail' => 'ana@example.com', 'isBusiness' => false,
            ...$overrides,
        ];
    }

    private function line(array $overrides = []): array
    {
        return [
            'productId' => 1, 'name' => 'Шампон', 'brand' => 'Brand A', 'category' => 'Shampoo',
            'qty' => 2, 'revenue' => 1000, 'unitCost' => 300, 'costSource' => 'real', 'stock' => 5,
            ...$overrides,
        ];
    }

    #[Test]
    public function cancelled_orders_are_not_revenue_but_are_counted_apart()
    {
        $summary = Calc::summary([
            $this->order(['total' => 1150, 'shipping' => 150]),
            $this->order(['id' => 2, 'status' => 3, 'total' => 5000]),
        ], [$this->line()], [], $this->start, $this->end);

        $this->assertSame(1150.0, $summary['revenue']);
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(1150.0, $summary['averageOrderValue']);
        $this->assertSame(1, $summary['cancelledOrders']);
        $this->assertSame(5000.0, $summary['cancelledValue']);
    }

    #[Test]
    public function store_credit_spent_still_counts_as_revenue()
    {
        $summary = Calc::summary([$this->order(['total' => 700, 'storeCreditUsed' => 300])], [], [], $this->start, $this->end);

        $this->assertSame(1000.0, $summary['revenue']);
        $this->assertSame(300.0, $summary['storeCreditUsed']);
    }

    #[Test]
    public function profit_takes_off_discounts_referral_credit_and_cost_but_not_shipping()
    {
        $summary = Calc::summary(
            [$this->order(['total' => 1050, 'discount' => 100, 'shipping' => 150, 'referralCommission' => 50])],
            [$this->line()],
            [],
            $this->start,
            $this->end
        );

        // 1000 sales − 100 discount − 50 credit − 600 cost
        $this->assertSame(250.0, $summary['profit']);
        $this->assertSame(27.8, $summary['marginPercentage']);
        $this->assertFalse($summary['profitEstimated']);
        $this->assertSame(100.0, $summary['costCoveragePercentage']);
    }

    #[Test]
    public function missing_purchase_costs_mark_the_profit_as_estimated()
    {
        $summary = Calc::summary([$this->order(['total' => 2000])], [
            $this->line(),
            $this->line(['productId' => 2, 'revenue' => 1000, 'unitCost' => 325, 'costSource' => 'estimated']),
        ], [], $this->start, $this->end);

        $this->assertTrue($summary['profitEstimated']);
        $this->assertSame(50.0, $summary['costCoveragePercentage']);
        $this->assertSame(750.0, $summary['profit']);
    }

    #[Test]
    public function an_unknown_cost_claims_no_profit()
    {
        $products = Calc::products([$this->line(['unitCost' => null, 'costSource' => 'unknown'])]);

        $this->assertSame(0.0, $products[0]['profit']);
        $this->assertSame('unknown', $products[0]['costSource']);
    }

    #[Test]
    public function customers_are_new_only_when_their_first_order_is_in_the_period()
    {
        $orders = [
            $this->order(),
            $this->order(['id' => 2]),
            $this->order(['id' => 3, 'customerKey' => 'old@example.com']),
            $this->order(['id' => 4, 'customerKey' => null]),
        ];
        $first = ['ana@example.com' => '2026-11-03 10:00:00', 'old@example.com' => '2024-05-01 09:00:00'];

        $summary = Calc::summary($orders, [], $first, $this->start, $this->end);

        $this->assertSame(2, $summary['customers']);
        $this->assertSame(1, $summary['newCustomers']);
        $this->assertSame(1, $summary['returningCustomers']);
        $this->assertSame(1, $summary['unidentifiedOrders']);
    }

    #[Test]
    public function business_account_share_is_by_order_count_and_by_revenue()
    {
        $summary = Calc::summary([
            $this->order(['total' => 3000, 'isBusiness' => true]),
            $this->order(['id' => 2, 'total' => 1000]),
        ], [], [], $this->start, $this->end);

        $this->assertSame(1, $summary['businessOrders']);
        $this->assertSame(50.0, $summary['businessOrderShare']);
        $this->assertSame(75.0, $summary['businessRevenueShare']);
    }

    #[Test]
    public function compare_gives_percentage_change_and_null_without_a_base()
    {
        $changes = Calc::compare(['revenue' => 1500.0, 'orders' => 3, 'marginPercentage' => null], ['revenue' => 1000.0, 'orders' => 0]);

        $this->assertSame(50.0, $changes['revenue']);
        $this->assertNull($changes['orders']);
        $this->assertArrayNotHasKey('marginPercentage', $changes);
    }

    #[Test]
    public function timeline_has_a_bar_for_every_day_including_empty_ones()
    {
        $period = ReportPeriod::resolve('this_month', now: CarbonImmutable::parse('2026-11-09 12:00'));
        $timeline = Calc::timeline([
            $this->order(['createdAt' => '2026-11-03 10:00:00']),
            $this->order(['id' => 2, 'createdAt' => '2026-11-03 18:00:00', 'total' => 500]),
            $this->order(['id' => 3, 'createdAt' => '2026-11-04 10:00:00', 'status' => 3]),
        ], $period);

        $this->assertCount(9, $timeline);
        $this->assertSame(['date' => '2026-11-03', 'revenue' => 1500.0, 'orders' => 2], $timeline[2]);
        $this->assertSame(0, $timeline[3]['orders']);
    }

    #[Test]
    public function by_status_lists_every_status()
    {
        $rows = Calc::byStatus([$this->order(), $this->order(['id' => 2, 'status' => 3, 'total' => 400])]);

        $this->assertSame(['pending', 'confirmed', 'shipped', 'cancelled'], array_column($rows, 'status'));
        $this->assertSame(400.0, $rows[3]['value']);
    }

    #[Test]
    public function brands_and_categories_are_grouped_with_unassigned_last_resort()
    {
        $rows = Calc::groupLines([
            $this->line(),
            $this->line(['productId' => 2, 'revenue' => 3000, 'qty' => 3, 'unitCost' => 500]),
            $this->line(['productId' => 3, 'brand' => null, 'revenue' => 200, 'qty' => 1, 'unitCost' => 100]),
        ], 'brand');

        $this->assertSame(['name' => 'Brand A', 'qty' => 5, 'revenue' => 4000.0, 'profit' => 1900.0], $rows[0]);
        $this->assertSame('Unassigned', $rows[1]['name']);
    }

    #[Test]
    public function best_customers_are_ranked_by_spend()
    {
        $rows = Calc::customers([
            $this->order(['total' => 500]),
            $this->order(['id' => 2, 'total' => 700, 'createdAt' => '2026-11-07 09:00:00']),
            $this->order(['id' => 3, 'customerKey' => 'salon@example.com', 'customerName' => 'Salon', 'total' => 900, 'isBusiness' => true]),
            $this->order(['id' => 4, 'customerKey' => 'salon@example.com', 'status' => 3, 'total' => 9000]),
        ], [], $this->start, $this->end);

        $this->assertSame('ana@example.com', $rows[0]['key']);
        $this->assertSame(2, $rows[0]['orders']);
        $this->assertSame(1200.0, $rows[0]['revenue']);
        $this->assertSame('2026-11-07', $rows[0]['lastOrderAt']);
        $this->assertTrue($rows[1]['isBusiness']);
        $this->assertSame(900.0, $rows[1]['revenue']);
    }

    #[Test]
    public function codes_sum_discount_and_referral_credit_per_code()
    {
        $rows = Calc::codes([
            $this->order(['couponCode' => 'ana10', 'discount' => 100, 'referralCommission' => 50]),
            $this->order(['id' => 2, 'couponCode' => 'ANA10', 'discount' => 100, 'referralCommission' => 50]),
            $this->order(['id' => 3, 'couponCode' => 'WINTER', 'discount' => 200]),
            $this->order(['id' => 4, 'couponCode' => 'WINTER', 'status' => 3]),
        ], ['ANA10' => ['isReferral' => true, 'stylistName' => 'Ana']]);

        $this->assertSame('ANA10', $rows[0]['code']);
        $this->assertSame(2, $rows[0]['orders']);
        $this->assertSame(100.0, $rows[0]['referralCredit']);
        $this->assertTrue($rows[0]['isReferral']);
        $this->assertSame(15.0, $rows[0]['costPercentage']);
        $this->assertSame(1, $rows[1]['orders']);
        $this->assertFalse($rows[1]['isReferral']);
    }
}
