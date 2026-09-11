<?php

namespace App\Support;

use App\Enums\ScheduleFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

class NextRunCalculator
{
    /**
     * The next run after $from, stored as UTC.
     *
     * Daily and weekly times are wall-clock times in $timezone. When that
     * clock time does not exist (spring-forward), the day is skipped. When it
     * exists twice (fall-back), the first occurrence is used. Interval
     * schedules advance by elapsed hours and are not tied to a clock time.
     *
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}|null  $schedule
     */
    public function next(?array $schedule, string $timezone, CarbonInterface $from): ?CarbonImmutable
    {
        $frequency = ScheduleFrequency::tryFrom($schedule['frequency'] ?? '');

        if ($frequency === null) {
            return null;
        }

        $from = CarbonImmutable::instance($from);

        return match ($frequency) {
            ScheduleFrequency::Daily => $this->nextDaily($this->time($schedule), $timezone, $from),
            ScheduleFrequency::Weekly => $this->nextWeekly($this->time($schedule), $this->weekdays($schedule), $timezone, $from),
            ScheduleFrequency::Interval => $this->nextInterval($this->hours($schedule), $from),
        };
    }

    /**
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}|null  $schedule
     * @return list<CarbonImmutable>
     */
    public function upcoming(?array $schedule, string $timezone, CarbonInterface $from, int $count = 5): array
    {
        $runs = [];
        $cursor = CarbonImmutable::instance($from);

        for ($i = 0; $i < $count; $i++) {
            $next = $this->next($schedule, $timezone, $cursor);

            if ($next === null) {
                break;
            }

            $runs[] = $next;
            $cursor = $next;
        }

        return $runs;
    }

    /**
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}  $schedule
     */
    private function time(array $schedule): string
    {
        return $schedule['time'] ?? '00:00';
    }

    /**
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}  $schedule
     * @return list<int>
     */
    private function weekdays(array $schedule): array
    {
        return array_map(intval(...), $schedule['weekdays'] ?? []);
    }

    /**
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}  $schedule
     */
    private function hours(array $schedule): int
    {
        return (int) ($schedule['hours'] ?? 0);
    }

    private function nextDaily(string $time, string $timezone, CarbonImmutable $from): CarbonImmutable
    {
        return $this->nextClockOccurrence($time, $timezone, $from, weekdays: null);
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function nextWeekly(string $time, array $weekdays, string $timezone, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($weekdays === []) {
            return null;
        }

        return $this->nextClockOccurrence($time, $timezone, $from, $weekdays);
    }

    private function nextInterval(int $hours, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($hours < 1) {
            return null;
        }

        return $from->utc()->addHours($hours);
    }

    /**
     * @param  list<int>|null  $weekdays
     */
    private function nextClockOccurrence(string $time, string $timezone, CarbonImmutable $from, ?array $weekdays): CarbonImmutable
    {
        $localFrom = $from->timezone($timezone);
        $day = $localFrom->startOfDay();

        for ($i = 0; $i < 400; $i++, $day = $day->addDay()) {
            $candidate = $this->atClockTime($day, $time);

            if ($candidate === null) {
                continue;
            }

            if ($weekdays !== null && ! in_array($candidate->dayOfWeekIso, $weekdays, true)) {
                continue;
            }

            if ($candidate->greaterThan($localFrom)) {
                return $candidate->utc();
            }
        }

        throw new RuntimeException('Could not find a scheduled occurrence within 400 days.');
    }

    /**
     * The given wall-clock time on this local day, or null when DST skips it.
     */
    private function atClockTime(CarbonImmutable $day, string $time): ?CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        $candidate = $day->setTime($hour, $minute, 0);

        if ($candidate->format('H:i') !== sprintf('%02d:%02d', $hour, $minute)) {
            return null;
        }

        return $candidate;
    }
}
