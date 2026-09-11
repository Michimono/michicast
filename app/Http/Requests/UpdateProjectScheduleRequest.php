<?php

namespace App\Http\Requests;

use App\Enums\ScheduleFrequency;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class UpdateProjectScheduleRequest extends FormRequest
{
    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(CurrentOrganization $current): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        if ($project->organization()->isNot($current->organization())) {
            abort(404);
        }

        return $this->user()?->can('update', $project) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
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
     * @return array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}
     */
    public function schedule(): array
    {
        $frequency = $this->enum('frequency', ScheduleFrequency::class);

        if (! $frequency instanceof ScheduleFrequency) {
            throw new InvalidArgumentException('A schedule cannot be built without a frequency.');
        }

        return match ($frequency) {
            ScheduleFrequency::Daily => [
                'frequency' => ScheduleFrequency::Daily->value,
                'time' => $this->string('time')->toString(),
            ],
            ScheduleFrequency::Weekly => [
                'frequency' => ScheduleFrequency::Weekly->value,
                'time' => $this->string('time')->toString(),
                'weekdays' => $this->weekdayValues(),
            ],
            ScheduleFrequency::Interval => [
                'frequency' => ScheduleFrequency::Interval->value,
                'hours' => $this->integer('hours'),
            ],
        };
    }

    public function timezoneOverride(): ?string
    {
        $timezone = $this->input('timezone');

        if (! is_string($timezone) || $timezone === '') {
            return null;
        }

        return $timezone;
    }

    public function effectiveTimezone(Project $project): string
    {
        return $this->timezoneOverride() ?? $project->organization->timezone;
    }

    public function timezoneSource(Project $project): string
    {
        return $this->timezoneOverride() === null
            ? 'organization timezone'
            : 'project override';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'time.required_if' => 'A time is required for daily and weekly schedules.',
            'weekdays.required_if' => 'Choose at least one weekday.',
            'hours.required_if' => 'Choose how many hours between runs.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('timezone') === '') {
            $this->merge(['timezone' => null]);
        }

        if ($this->input('hours') === '') {
            $this->merge(['hours' => null]);
        }

        $time = $this->input('time');

        if (is_string($time) && preg_match('/^(\d{2}:\d{2}):\d{2}$/', $time, $matches) === 1) {
            $this->merge(['time' => $matches[1]]);
        }
    }

    /**
     * @return list<int>
     */
    private function weekdayValues(): array
    {
        $weekdays = array_map(intval(...), $this->input('weekdays', []));
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return $weekdays;
    }
}
