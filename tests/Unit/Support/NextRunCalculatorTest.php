<?php

namespace Tests\Unit\Support;

use App\Support\NextRunCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NextRunCalculatorTest extends TestCase
{
    public function test_a_daily_schedule_uses_the_next_clock_time_in_the_given_timezone(): void
    {
        $from = CarbonImmutable::parse('2026-03-07 12:00:00', 'Europe/Madrid');

        $next = $this->calculator()->next(
            ['frequency' => 'daily', 'time' => '09:30'],
            'Europe/Madrid',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-03-08 09:30:00', 'Europe/Madrid')->utc()));
    }

    public function test_a_weekly_schedule_lands_on_the_next_chosen_weekday(): void
    {
        $from = CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC');

        $next = $this->calculator()->next(
            ['frequency' => 'weekly', 'time' => '09:00', 'weekdays' => [1, 3]],
            'UTC',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-03-09 09:00:00', 'UTC')));
    }

    public function test_an_interval_schedule_advances_by_elapsed_hours(): void
    {
        $from = CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC');

        $next = $this->calculator()->next(
            ['frequency' => 'interval', 'hours' => 6],
            'America/New_York',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-03-07 18:00:00', 'UTC')));
    }

    public function test_a_project_timezone_override_is_used_instead_of_another_zone(): void
    {
        $from = CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC');

        $next = $this->calculator()->next(
            ['frequency' => 'daily', 'time' => '15:00'],
            'Europe/Madrid',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-03-07 15:00:00', 'Europe/Madrid')->utc()));
        $this->assertFalse($next->equalTo(CarbonImmutable::parse('2026-03-07 15:00:00', 'America/New_York')->utc()));
    }

    public function test_a_spring_forward_gap_skips_the_missing_clock_time(): void
    {
        $from = CarbonImmutable::parse('2026-03-07 12:00:00', 'America/New_York');

        $next = $this->calculator()->next(
            ['frequency' => 'daily', 'time' => '02:30'],
            'America/New_York',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-03-09 02:30:00', 'America/New_York')->utc()));
        $this->assertSame('02:30', $next->timezone('America/New_York')->format('H:i'));
    }

    public function test_a_fall_back_overlap_uses_the_first_occurrence(): void
    {
        $from = CarbonImmutable::parse('2026-10-31 12:00:00', 'America/New_York');

        $next = $this->calculator()->next(
            ['frequency' => 'daily', 'time' => '01:30'],
            'America/New_York',
            $from,
        );

        $this->assertNotNull($next);
        $this->assertTrue($next->equalTo(CarbonImmutable::parse('2026-11-01 01:30:00', 'America/New_York')->utc()));
        $this->assertSame('01:30', $next->timezone('America/New_York')->format('H:i'));
    }

    public function test_upcoming_runs_list_the_next_occurrences(): void
    {
        $from = CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC');

        $upcoming = $this->calculator()->upcoming(
            ['frequency' => 'interval', 'hours' => 6],
            'UTC',
            $from,
            3,
        );

        $this->assertTrue($upcoming[0]->equalTo(CarbonImmutable::parse('2026-03-07 18:00:00', 'UTC')));
        $this->assertTrue($upcoming[1]->equalTo(CarbonImmutable::parse('2026-03-08 00:00:00', 'UTC')));
        $this->assertTrue($upcoming[2]->equalTo(CarbonImmutable::parse('2026-03-08 06:00:00', 'UTC')));
    }

    public function test_a_missing_schedule_has_no_next_run(): void
    {
        $this->assertNull($this->calculator()->next(null, 'UTC', CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC')));
    }

    #[DataProvider('frequencies')]
    public function test_each_frequency_returns_a_utc_instant(string $frequency, array $schedule): void
    {
        $next = $this->calculator()->next(
            $schedule,
            'UTC',
            CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC'),
        );

        $this->assertNotNull($next);
        $this->assertSame('UTC', $next->timezoneName);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function frequencies(): array
    {
        return [
            'daily' => ['daily', ['frequency' => 'daily', 'time' => '09:00']],
            'weekly' => ['weekly', ['frequency' => 'weekly', 'time' => '09:00', 'weekdays' => [1]]],
            'interval' => ['interval', ['frequency' => 'interval', 'hours' => 3]],
        ];
    }

    private function calculator(): NextRunCalculator
    {
        return new NextRunCalculator;
    }
}
