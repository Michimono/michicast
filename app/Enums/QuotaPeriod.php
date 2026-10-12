<?php

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Calendar period a rate quota is counted over.
 *
 * Boundaries are UTC calendar periods, matching the original daily reset: a
 * day starts at 00:00 UTC, a week on Monday 00:00 UTC, a month on the 1st at
 * 00:00 UTC. They are calendar periods, not rolling windows.
 */
enum QuotaPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * Start of the period containing $at (UTC).
     */
    public function startOf(?CarbonInterface $at = null): CarbonImmutable
    {
        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now())->utc();

        return match ($this) {
            self::Daily => $at->startOfDay(),
            self::Weekly => $at->startOfWeek(CarbonInterface::MONDAY),
            self::Monthly => $at->startOfMonth(),
        };
    }

    /**
     * When the period that starts at $periodStart ends and the counter resets.
     */
    public function resetsAt(CarbonInterface $periodStart): CarbonImmutable
    {
        $start = CarbonImmutable::parse($periodStart->toDateString(), 'UTC');

        return match ($this) {
            self::Daily => $start->addDay(),
            self::Weekly => $start->addWeek(),
            self::Monthly => $start->addMonthNoOverflow(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * Member-facing phrase for the current period: "today", "this week".
     */
    public function current(): string
    {
        return match ($this) {
            self::Daily => 'today',
            self::Weekly => 'this week',
            self::Monthly => 'this month',
        };
    }

    /**
     * Human reset time for messages, e.g. "00:00 UTC" or "Mon 12 Oct 00:00 UTC".
     */
    public function formatReset(CarbonInterface $resetsAt): string
    {
        $utc = CarbonImmutable::instance($resetsAt)->utc();

        return $this === self::Daily
            ? $utc->format('H:i').' UTC'
            : $utc->format('D j M H:i').' UTC';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $period) {
            $options[$period->value] = $period->label();
        }

        return $options;
    }
}
