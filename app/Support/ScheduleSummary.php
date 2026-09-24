<?php

namespace App\Support;

use App\Enums\ScheduleFrequency;

class ScheduleSummary
{
    /**
     * @param  array{frequency?: string, time?: string, weekdays?: list<int>, hours?: int}|null  $schedule
     */
    public function describe(?array $schedule): string
    {
        if ($schedule === null || ! isset($schedule['frequency'])) {
            return 'No schedule set';
        }

        $frequency = ScheduleFrequency::tryFrom($schedule['frequency']);

        if ($frequency === null) {
            return 'No schedule set';
        }

        return match ($frequency) {
            ScheduleFrequency::Daily => $this->daily($schedule),
            ScheduleFrequency::Weekly => $this->weekly($schedule),
            ScheduleFrequency::Interval => $this->interval($schedule),
        };
    }

    /**
     * @param  array{time?: string}  $schedule
     */
    private function daily(array $schedule): string
    {
        $time = $this->formatTime($schedule['time'] ?? null);

        return $time === null ? 'Daily' : 'Daily at '.$time;
    }

    /**
     * @param  array{time?: string, weekdays?: list<int>}  $schedule
     */
    private function weekly(array $schedule): string
    {
        $time = $this->formatTime($schedule['time'] ?? null);
        $days = $this->formatWeekdays($schedule['weekdays'] ?? []);
        $suffix = $time === null ? '' : ' at '.$time;

        if ($days === null) {
            return 'Weekly'.$suffix;
        }

        return $days.$suffix;
    }

    /**
     * @param  array{hours?: int}  $schedule
     */
    private function interval(array $schedule): string
    {
        $hours = $schedule['hours'] ?? null;

        if (! is_int($hours) || $hours < 1) {
            return 'Every few hours';
        }

        return $hours === 1 ? 'Every hour' : 'Every '.$hours.' hours';
    }

    private function formatTime(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $parts)) {
            return $time;
        }

        return ((int) $parts[1]).':'.$parts[2];
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function formatWeekdays(array $weekdays): ?string
    {
        $normalized = array_values(array_unique(array_map('intval', $weekdays)));
        sort($normalized);

        if ($normalized === []) {
            return null;
        }

        if ($normalized === [1, 2, 3, 4, 5]) {
            return 'Every weekday';
        }

        if ($normalized === [1, 2, 3, 4, 5, 6, 7]) {
            return 'Every day';
        }

        $labels = ScheduleFrequency::weekdayOptions();
        $names = [];

        foreach ($normalized as $day) {
            if (isset($labels[$day])) {
                $names[] = $labels[$day];
            }
        }

        if ($names === []) {
            return null;
        }

        if (count($names) === 1) {
            return 'Every '.$names[0];
        }

        $last = array_pop($names);

        return 'Weekly on '.implode(', ', $names).' and '.$last;
    }
}
