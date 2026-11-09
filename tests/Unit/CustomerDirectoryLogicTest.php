<?php

namespace Tests\Unit;

use App\Support\Customers\CustomerAggregator;
use App\Support\Customers\CustomerCsv;
use App\Support\Customers\CustomerKey;
use App\Support\Customers\CustomerQuery;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Customer list grouping, filters, sorting and CSV, without a database. */
class CustomerDirectoryLogicTest extends TestCase
{
    private function user(int $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'first_name' => 'User', 'last_name' => (string) $id, 'email' => "u$id@example.com",
            'phone' => null, 'city' => null, 'role' => 0, 'created_at' => '2026-01-01 10:00:00'];
    }

    private function order(int $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'user_id' => null, 'status' => 1, 'total' => 1000, 'created_at' => '2026-10-01 10:00:00',
            'first_name' => 'Guest', 'last_name' => 'Buyer', 'email' => null, 'phone' => null, 'city' => 'Skopje'];
    }

    private function build(array $users, array $orders, array $credits = [], array $notes = []): array
    {
        $rows = CustomerAggregator::build($users, $orders, $credits, $notes, new DateTimeImmutable('2026-11-09 12:00:00'));

        return array_column($rows, null, 'key');
    }

    #[Test]
    public function account_orders_and_matching_guest_orders_make_one_customer()
    {
        $rows = $this->build(
            [$this->user(1, ['phone' => '070 123 456', 'city' => 'Tetovo'])],
            [
                $this->order(10, ['user_id' => 1, 'total' => 1500, 'created_at' => '2026-09-01 09:00:00']),
                $this->order(11, ['email' => 'U1@Example.com', 'total' => 500, 'created_at' => '2026-10-20 09:00:00']),
                $this->order(12, ['phone' => '+389 70 123 456', 'total' => 1000, 'created_at' => '2026-11-01 09:00:00']),
                $this->order(13, ['user_id' => 1, 'status' => 3, 'total' => 9999]),
            ],
            [1 => 250.5],
        );

        $this->assertCount(1, $rows);
        $row = $rows['user-1'];
        $this->assertSame('customer', $row['type']);
        $this->assertSame(3, $row['ordersCount']);
        $this->assertSame(1, $row['cancelledCount']);
        $this->assertSame(3000.0, $row['totalSpent']);
        $this->assertSame(1000.0, $row['averageOrder']);
        $this->assertSame('2026-09-01 09:00:00', $row['firstOrderAt']);
        $this->assertSame('2026-11-01 09:00:00', $row['lastOrderAt']);
        $this->assertSame(8, $row['daysSinceLastOrder']);
        $this->assertSame(250.5, $row['storeCredit']);
        $this->assertSame('Tetovo', $row['city']);
        $this->assertContains('email-u1@example.com', $row['aliases']);
    }

    #[Test]
    public function guest_orders_sharing_an_email_or_phone_are_grouped()
    {
        $rows = $this->build([], [
            $this->order(1, ['email' => 'ana@x.mk', 'phone' => '078 111 222', 'first_name' => 'Ана', 'created_at' => '2026-05-01 10:00:00']),
            $this->order(2, ['phone' => '38978111222', 'created_at' => '2026-06-01 10:00:00']),
            $this->order(3, ['email' => 'ANA@x.mk', 'created_at' => '2026-07-01 10:00:00']),
            $this->order(4, ['email' => 'other@x.mk']),
            $this->order(5, ['phone' => '075 999 888']),
        ]);

        $this->assertSame(['email-ana@x.mk', 'email-other@x.mk', 'phone-75999888'], $this->sorted(array_keys($rows)));
        $ana = $rows['email-ana@x.mk'];
        $this->assertSame('guest', $ana['type']);
        $this->assertSame(3, $ana['ordersCount']);
        $this->assertContains('phone-78111222', $ana['aliases']);
        $this->assertSame('Guest Buyer', $ana['name']);
    }

    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    #[Test]
    public function business_accounts_and_people_without_orders_are_listed()
    {
        $rows = $this->build([$this->user(2, ['role' => 2]), $this->user(3)], []);

        $this->assertSame('business', $rows['user-2']['type']);
        $this->assertSame(0, $rows['user-3']['ordersCount']);
        $this->assertNull($rows['user-3']['lastOrderAt']);
        $this->assertSame('2026-01-01 10:00:00', $rows['user-3']['firstSeenAt']);
    }

    #[Test]
    public function two_accounts_with_one_phone_do_not_absorb_a_guest_order_by_phone()
    {
        $rows = $this->build(
            [$this->user(1, ['phone' => '070123456']), $this->user(2, ['phone' => '070 123 456'])],
            [$this->order(9, ['phone' => '070123456'])],
        );

        $this->assertSame(0, $rows['user-1']['ordersCount']);
        $this->assertSame(0, $rows['user-2']['ordersCount']);
        $this->assertSame(1, $rows['phone-70123456']['ordersCount']);
    }

    #[Test]
    public function notes_are_found_under_any_of_the_customers_keys()
    {
        $rows = $this->build(
            [$this->user(1)],
            [$this->order(1, ['email' => 'u1@example.com'])],
            [],
            [['customer_key' => 'email-u1@example.com', 'notes' => 'Likes Olaplex', 'tags' => ['VIP']]],
        );

        $this->assertSame('Likes Olaplex', $rows['user-1']['notes']);
        $this->assertSame(['VIP'], $rows['user-1']['tags']);
    }

    #[Test]
    public function filters_cover_segments_and_ranges()
    {
        $rows = array_values($this->build(
            [$this->user(1, ['role' => 2, 'city' => 'Skopje']), $this->user(2, ['city' => 'Bitola', 'created_at' => '2026-11-03 10:00:00'])],
            [
                $this->order(1, ['user_id' => 1, 'total' => 5000, 'created_at' => '2026-08-01 10:00:00']),
                $this->order(2, ['user_id' => 2, 'total' => 800, 'created_at' => '2026-11-05 10:00:00']),
                $this->order(3, ['email' => 'g@x.mk', 'phone' => '070 555 444', 'total' => 1200, 'created_at' => '2026-10-15 10:00:00']),
            ],
            [],
            [['customer_key' => 'user-2', 'notes' => null, 'tags' => ['Salon']]],
        ));
        $keys = fn (array $filters) => $this->sorted(array_column(CustomerQuery::filter($rows, $filters), 'key'));

        $this->assertSame(['user-1'], $keys(['type' => 'business']));
        $this->assertSame(['email-g@x.mk'], $keys(['type' => 'guest']));
        $this->assertSame(['user-1', 'user-2'], $keys(['type' => 'registered']));
        $this->assertSame(['user-2'], $keys(['city' => 'bitola']));
        $this->assertSame(['user-2'], $keys(['tag' => 'salon']));
        $this->assertSame(['email-g@x.mk', 'user-1'], $keys(['min_spent' => 1000]));
        $this->assertSame(['user-2'], $keys(['max_spent' => 1000]));
        $this->assertSame(['user-1'], $keys(['inactive_days' => 60]));
        $this->assertSame(['email-g@x.mk', 'user-2'], $keys(['last_order_after' => '2026-10-01']));
        $this->assertSame(['user-1'], $keys(['last_order_before' => '2026-09-30']));
        $this->assertSame(['user-2'], $keys(['new_since' => '2026-11-01']));
        $this->assertSame(['email-g@x.mk'], $keys(['q' => '555 444']));
        $this->assertSame(['email-g@x.mk'], $keys(['q' => '070555']));
        $this->assertSame(['user-1'], $keys(['q' => 'u1@ex']));
    }

    #[Test]
    public function sorting_puts_empty_values_last_both_ways()
    {
        $rows = [
            ['key' => 'a', 'totalSpent' => 100.0, 'lastOrderAt' => '2026-01-01 00:00:00'],
            ['key' => 'b', 'totalSpent' => 300.0, 'lastOrderAt' => null],
            ['key' => 'c', 'totalSpent' => 200.0, 'lastOrderAt' => '2026-05-01 00:00:00'],
        ];

        $this->assertSame(['b', 'c', 'a'], array_column(CustomerQuery::sort($rows, 'total_spent', 'desc'), 'key'));
        $this->assertSame(['c', 'a', 'b'], array_column(CustomerQuery::sort($rows, 'last_order_at', 'desc'), 'key'));
        $this->assertSame(['a', 'c', 'b'], array_column(CustomerQuery::sort($rows, 'last_order_at', 'asc'), 'key'));
    }

    #[Test]
    public function tags_are_cleaned()
    {
        $this->assertSame(['VIP', 'Salon Skopje'], CustomerQuery::cleanTags([' VIP ', 'vip', 'Salon   Skopje', '']));
    }

    #[Test]
    public function csv_has_a_bom_cyrillic_and_no_formulas()
    {
        $csv = CustomerCsv::make([[
            'name' => 'Ана Петровска', 'email' => 'ana@x.mk', 'phone' => '+389 70 123 456', 'city' => 'Скопје', 'type' => 'guest',
            'ordersCount' => 2, 'totalSpent' => 2500.0, 'averageOrder' => 1250.0, 'firstOrderAt' => '2026-01-02 10:00:00',
            'lastOrderAt' => '2026-11-01 10:00:00', 'daysSinceLastOrder' => 8, 'storeCredit' => 0.0, 'tags' => ['VIP', 'Salon'],
            'notes' => '=HYPERLINK("http://bad")',
        ]]);

        $this->assertStringStartsWith(CustomerCsv::BOM . 'Name,Email,Phone', $csv);
        $this->assertStringContainsString('"Ана Петровска",ana@x.mk,"+389 70 123 456",Скопје,"Guest (no account)",2,2500,1250,2026-01-02,2026-11-01,8,0,"VIP, Salon"', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://bad"")"', $csv);
        $this->assertSame("'-cmd", CustomerCsv::cell('-cmd'));
    }

    #[Test]
    public function keys_are_validated_and_normalised()
    {
        $this->assertSame('70123456', CustomerKey::phone('+389 70 123 456'));
        $this->assertNull(CustomerKey::phone('123'));
        $this->assertSame(12, CustomerKey::userId('user-12'));
        $this->assertTrue(CustomerKey::isValid('email-ana@x.mk'));
        $this->assertFalse(CustomerKey::isValid('email-../etc'));
        $this->assertFalse(CustomerKey::isValid('drop table'));
    }
}
