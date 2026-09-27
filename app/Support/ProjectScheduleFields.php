<?php

namespace App\Support;

use App\Enums\ScheduleFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Shared schedule field rules and payload for create, edit, and the legacy schedule routes.
 */
class ProjectScheduleFields
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'frequency' => ['required', Rule::enum(ScheduleFrequency::class)],
            'time' => ['nullable', 'required_if:frequency,daily', 'required_if:frequency,weekly', 'date_format:H:i'],
            'weekdays' => ['nullable', 'required_if:frequency,weekly', 'array'],
            'weekdays.*' => ['integer', Rule::in(array_keys(ScheduleFrequency::weekdayOptions()))],
            'hours' => ['nullable', 'required_if:frequency,interval', 'integer', 'min:1', 'max:168'],
            'timezone' => ['nullable', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'time.required_if' => 'A time is required for daily and weekly schedules.',
            'weekdays.required_if' => 'Choose at least one weekday.',
            'hours.required_if' => 'Choose how many hours between runs.',
        ];
    }

    public static function prepare(FormRequest $request): void
    {
        if ($request->input('timezone') === '') {
            $request->merge(['timezone' => null]);
        }

        if ($request->input('hours') === '') {
            $request->merge(['hours' => null]);
        }

        $time = $request->input('time');

        if (is_string($time) && preg_match('/^(\d{2}:\d{2}):\d{2}$/', $time, $matches) === 1) {
            $request->merge(['time' => $matches[1]]);
        }
    }

    /**
     * @return array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}
     */
    public static function schedule(FormRequest $request): array
    {
        $frequency = $request->enum('frequency', ScheduleFrequency::class);

        if (! $frequency instanceof ScheduleFrequency) {
            throw new InvalidArgumentException('A schedule cannot be built without a frequency.');
        }

        return match ($frequency) {
            ScheduleFrequency::Daily => [
                'frequency' => ScheduleFrequency::Daily->value,
                'time' => $request->string('time')->toString(),
            ],
            ScheduleFrequency::Weekly => [
                'frequency' => ScheduleFrequency::Weekly->value,
                'time' => $request->string('time')->toString(),
                'weekdays' => self::weekdayValues($request),
            ],
            ScheduleFrequency::Interval => [
                'frequency' => ScheduleFrequency::Interval->value,
                'hours' => $request->integer('hours'),
            ],
        };
    }

    public static function timezoneOverride(FormRequest $request): ?string
    {
        $timezone = $request->input('timezone');

        if (! is_string($timezone) || $timezone === '') {
            return null;
        }

        return $timezone;
    }

    /**
     * @return list<int>
     */
    private static function weekdayValues(FormRequest $request): array
    {
        $weekdays = array_map(intval(...), $request->input('weekdays', []));
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return $weekdays;
    }
}
