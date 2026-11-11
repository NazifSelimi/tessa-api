<?php

namespace Tests\Unit;

use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-09 15:30:00'); // a Monday
    }

    #[Test]
    public function this_month_is_compared_with_the_same_days_of_last_month()
    {
        $period = ReportPeriod::resolve('this_month', now: $this->now());

        $this->assertSame(['2026-11-01', '2026-11-09', '2026-10-01', '2026-10-09'], [
            $period->start->toDateString(), $period->end->toDateString(),
            $period->previousStart->toDateString(), $period->previousEnd->toDateString(),
        ]);
        $this->assertSame('day', $period->bucket);
    }

    #[Test]
    public function the_comparison_never_runs_past_a_shorter_month()
    {
        $period = ReportPeriod::resolve('this_month', now: CarbonImmutable::parse('2026-03-31 10:00'));

        $this->assertSame('2026-02-28', $period->previousEnd->toDateString());
    }

    #[Test]
    public function last_month_is_compared_with_the_month_before()
    {
        $period = ReportPeriod::resolve('last_month', now: $this->now());

        $this->assertSame('2026-10-01', $period->start->toDateString());
        $this->assertSame('2026-10-31', $period->end->toDateString());
        $this->assertSame('2026-09-01', $period->previousStart->toDateString());
        $this->assertSame('2026-09-30', $period->previousEnd->toDateString());
    }

    #[Test]
    public function this_week_starts_on_monday()
    {
        $period = ReportPeriod::resolve('this_week', now: CarbonImmutable::parse('2026-11-12 09:00'));

        $this->assertSame('2026-11-09', $period->start->toDateString());
        $this->assertSame('2026-11-02', $period->previousStart->toDateString());
        $this->assertSame('2026-11-05', $period->previousEnd->toDateString());
    }

    #[Test]
    public function this_year_uses_monthly_bars()
    {
        $period = ReportPeriod::resolve('this_year', now: $this->now());

        $this->assertSame('2025-01-01', $period->previousStart->toDateString());
        $this->assertSame('2025-11-09', $period->previousEnd->toDateString());
        $this->assertSame('month', $period->bucket);
        $this->assertCount(11, $period->bucketKeys());
    }

    #[Test]
    public function a_custom_range_is_compared_with_the_equally_long_range_before_it()
    {
        $period = ReportPeriod::resolve('custom', '2026-10-10', '2026-10-19', $this->now());

        $this->assertSame('2026-09-30', $period->previousStart->toDateString());
        $this->assertSame('2026-10-09', $period->previousEnd->toDateString());
        $this->assertSame(10, $period->toArray()['days']);
    }

    #[Test]
    public function weekly_bars_start_on_monday()
    {
        $period = ReportPeriod::resolve('custom', '2026-08-05', '2026-10-01', $this->now());

        $this->assertSame('week', $period->bucket);
        $this->assertSame('2026-08-03', $period->bucketKeys()[0]);
        $this->assertSame('2026-08-03', ReportPeriod::bucketKey(CarbonImmutable::parse('2026-08-09 23:00'), 'week'));
    }

    #[Test]
    public function an_explicit_bar_size_wins()
    {
        $this->assertSame('week', ReportPeriod::resolve('this_month', now: $this->now(), bucket: 'week')->bucket);
    }

    #[Test]
    public function a_custom_range_needs_both_dates_in_order()
    {
        $this->expectException(InvalidArgumentException::class);
        ReportPeriod::resolve('custom', '2026-10-19', '2026-10-10', $this->now());
    }
}
