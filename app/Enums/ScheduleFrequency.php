<?php

namespace App\Enums;

enum ScheduleFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Interval = 'interval';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Interval => 'Every few hours',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $frequency) {
            $options[$frequency->value] = $frequency->label();
        }

        return $options;
    }

    /**
     * ISO-8601 weekdays, Monday first, keyed by the value Carbon uses.
     *
     * @return array<int, string>
     */
    public static function weekdayOptions(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }
}
