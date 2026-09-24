<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScheduleSummaryTest extends TestCase
{
    #[DataProvider('schedules')]
    public function test_it_describes_schedules_in_plain_language(?array $schedule, string $expected): void
    {
        $this->assertSame($expected, (new ScheduleSummary)->describe($schedule));
    }

    /**
     * @return array<string, array{0: ?array, 1: string}>
     */
    public static function schedules(): array
    {
        return [
            'missing' => [null, 'No schedule set'],
            'empty' => [[], 'No schedule set'],
            'daily' => [['frequency' => 'daily', 'time' => '09:00'], 'Daily at 9:00'],
            'daily afternoon' => [['frequency' => 'daily', 'time' => '14:00'], 'Daily at 14:00'],
            'weekdays' => [['frequency' => 'weekly', 'time' => '09:00', 'weekdays' => [1, 2, 3, 4, 5]], 'Every weekday at 9:00'],
            'single weekday' => [['frequency' => 'weekly', 'time' => '10:30', 'weekdays' => [1]], 'Every Monday at 10:30'],
            'two weekdays' => [['frequency' => 'weekly', 'time' => '08:00', 'weekdays' => [1, 3]], 'Weekly on Monday and Wednesday at 8:00'],
            'interval' => [['frequency' => 'interval', 'hours' => 6], 'Every 6 hours'],
            'hourly' => [['frequency' => 'interval', 'hours' => 1], 'Every hour'],
        ];
    }
}
