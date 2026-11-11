<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting window plus the window it is compared with.
 *
 * "This week/month/year" run to the end of today and are compared with the
 * same stretch of the previous week/month/year (1–9 November against 1–9
 * October), so a half-finished month is never measured against a full one.
 * "Last month" is compared with the month before it, and a custom range with
 * the equally long range right before it.
 */
final class ReportPeriod
{
    public const PRESETS = ['this_week', 'this_month', 'last_month', 'this_year', 'custom'];

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly CarbonImmutable $previousStart,
        public readonly CarbonImmutable $previousEnd,
        public readonly string $bucket,
    ) {}

    public static function resolve(string $preset, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null, ?string $bucket = null): self
    {
        $now ??= CarbonImmutable::now();
        $today = $now->endOfDay();

        [$start, $end, $previousStart, $previousEnd] = match ($preset) {
            'this_week' => [
                $now->startOfWeek(CarbonImmutable::MONDAY),
                $today,
                $now->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
                $today->subWeek(),
            ],
            'this_month' => [
                $now->startOfMonth(),
                $today,
                $now->startOfMonth()->subMonthNoOverflow(),
                $today->subMonthNoOverflow(),
            ],
            'last_month' => [
                $now->startOfMonth()->subMonthNoOverflow(),
                $now->startOfMonth()->subMonthNoOverflow()->endOfMonth(),
                $now->startOfMonth()->subMonthsNoOverflow(2),
                $now->startOfMonth()->subMonthsNoOverflow(2)->endOfMonth(),
            ],
            'this_year' => [
                $now->startOfYear(),
                $today,
                $now->startOfYear()->subYear(),
                $today->subYearNoOverflow(),
            ],
            'custom' => self::custom($from, $to),
            default => throw new InvalidArgumentException("Unknown report period [{$preset}]."),
        };

        return new self($preset, $start, $end, $previousStart, $previousEnd, $bucket ?? self::bucketFor($start, $end));
    }

    /** Days for up to ~6 weeks, weeks up to ~6 months, months beyond. */
    public static function bucketFor(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $days = self::days($start, $end);

        return match (true) {
            $days <= 45 => 'day',
            $days <= 190 => 'week',
            default => 'month',
        };
    }

    /** Calendar days covered, counting both ends. */
    public static function days(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
    }

    /** Key of the chart bar a moment falls in. */
    public static function bucketKey(CarbonImmutable $moment, string $bucket): string
    {
        return match ($bucket) {
            'week' => $moment->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $moment->format('Y-m'),
            default => $moment->format('Y-m-d'),
        };
    }

    /** Every bar key from start to end, so days without sales still show as 0. */
    public function bucketKeys(): array
    {
        $keys = [];
        $cursor = match ($this->bucket) {
            'week' => $this->start->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->start->startOfMonth(),
            default => $this->start->startOfDay(),
        };

        while ($cursor <= $this->end) {
            $keys[] = self::bucketKey($cursor, $this->bucket);
            $cursor = match ($this->bucket) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'previousStart' => $this->previousStart->toDateString(),
            'previousEnd' => $this->previousEnd->toDateString(),
            'days' => self::days($this->start, $this->end),
            'bucket' => $this->bucket,
        ];
    }

    private static function custom(?string $from, ?string $to): array
    {
        if (!$from || !$to) {
            throw new InvalidArgumentException('A custom period needs a start and an end date.');
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        if ($end < $start) {
            throw new InvalidArgumentException('The end date must not be before the start date.');
        }

        $days = self::days($start, $end);
        $previousEnd = $start->subDay()->endOfDay();

        return [$start, $end, $previousEnd->subDays($days - 1)->startOfDay(), $previousEnd];
    }
}
