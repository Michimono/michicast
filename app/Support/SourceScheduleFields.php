<?php

namespace App\Support;

use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The optional nested schedule a source of a visual broadcast carries.
 * Absent means "leave it as it is"; null means "follow the broadcast".
 */
class SourceScheduleFields
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'schedule' => ['nullable', 'array'],
            'schedule.frequency' => ['required_with:schedule', Rule::enum(ScheduleFrequency::class)],
            'schedule.time' => ['nullable', 'required_if:schedule.frequency,daily,weekly', 'date_format:H:i'],
            'schedule.weekdays' => ['nullable', 'required_if:schedule.frequency,weekly', 'array'],
            'schedule.weekdays.*' => ['integer', Rule::in(array_keys(ScheduleFrequency::weekdayOptions()))],
            'schedule.hours' => ['nullable', 'required_if:schedule.frequency,interval', 'integer', 'min:1', 'max:168'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'schedule.frequency.required_with' => 'Choose how often this source runs.',
            'schedule.time.required_if' => 'A time is required for daily and weekly schedules.',
            'schedule.weekdays.required_if' => 'Choose at least one weekday.',
            'schedule.hours.required_if' => 'Choose how many hours between runs.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'schedule.frequency' => 'frequency',
            'schedule.time' => 'time',
            'schedule.weekdays' => 'weekdays',
            'schedule.hours' => 'hours',
        ];
    }

    public static function prepare(FormRequest $request): void
    {
        $schedule = $request->input('schedule');

        if ($schedule === '') {
            $request->merge(['schedule' => null]);

            return;
        }

        if (! is_array($schedule)) {
            return;
        }

        if (($schedule['hours'] ?? null) === '') {
            $schedule['hours'] = null;
        }

        if (($schedule['time'] ?? null) === '') {
            $schedule['time'] = null;
        }

        if (is_string($schedule['time'] ?? null) && preg_match('/^(\d{2}:\d{2}):\d{2}$/', $schedule['time'], $matches) === 1) {
            $schedule['time'] = $matches[1];
        }

        $request->merge(['schedule' => $schedule]);
    }

    public static function provided(FormRequest $request): bool
    {
        return $request->exists('schedule');
    }

    /**
     * @return array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}|null
     */
    public static function schedule(FormRequest $request): ?array
    {
        $frequency = ScheduleFrequency::tryFrom((string) $request->input('schedule.frequency', ''));

        if ($frequency === null) {
            return null;
        }

        return match ($frequency) {
            ScheduleFrequency::Daily => [
                'frequency' => ScheduleFrequency::Daily->value,
                'time' => (string) $request->input('schedule.time'),
            ],
            ScheduleFrequency::Weekly => [
                'frequency' => ScheduleFrequency::Weekly->value,
                'time' => (string) $request->input('schedule.time'),
                'weekdays' => self::weekdays($request),
            ],
            ScheduleFrequency::Interval => [
                'frequency' => ScheduleFrequency::Interval->value,
                'hours' => (int) $request->input('schedule.hours'),
            ],
        };
    }

    /**
     * @return list<int>
     */
    private static function weekdays(FormRequest $request): array
    {
        $raw = $request->input('schedule.weekdays', []);
        $weekdays = array_values(array_unique(array_map(intval(...), is_array($raw) ? $raw : [])));
        sort($weekdays);

        return $weekdays;
    }
}
