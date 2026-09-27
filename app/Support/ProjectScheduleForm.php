<?php

namespace App\Support;

use App\Enums\ScheduleFrequency;
use App\Http\Requests\UpdateProjectScheduleRequest;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeZone;

class ProjectScheduleForm
{
    public function __construct(private NextRunCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(Project $project, ?UpdateProjectScheduleRequest $request = null): array
    {
        $schedule = $request?->schedule() ?? $project->schedule;
        $timezone = $request?->effectiveTimezone($project) ?? $project->effectiveTimezone();
        $source = $request?->timezoneSource($project)
            ?? ($project->hasTimezoneOverride() ? 'broadcast override' : 'organization timezone');

        $upcoming = array_map(
            fn (CarbonImmutable $run): string => $run->timezone($timezone)->format('l, F j, Y \a\t g:i A T'),
            $this->calculator->upcoming($schedule, $timezone, now()),
        );

        return [
            'project' => $project,
            'frequencies' => ScheduleFrequency::options(),
            'weekdays' => ScheduleFrequency::weekdayOptions(),
            'timezones' => $this->timezoneOptions($project->organization),
            'schedule' => $schedule ?? ['frequency' => ScheduleFrequency::Daily->value],
            'upcoming' => $upcoming,
            'timezoneName' => $timezone,
            'timezoneSource' => $source,
            'selectedTimezone' => $request?->timezoneOverride() ?? $project->timezone,
        ];
    }

    /**
     * Defaults for the create-broadcast form (no project yet).
     *
     * @return array<string, mixed>
     */
    public function forCreate(Organization $organization): array
    {
        return [
            'frequencies' => ScheduleFrequency::options(),
            'weekdays' => ScheduleFrequency::weekdayOptions(),
            'timezones' => $this->timezoneOptions($organization),
            'schedule' => [
                'frequency' => ScheduleFrequency::Daily->value,
                'time' => '09:00',
            ],
            'upcoming' => [],
            'timezoneName' => $organization->timezone,
            'timezoneSource' => 'organization timezone',
            'selectedTimezone' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function timezoneOptions(Organization $organization): array
    {
        $options = [
            '' => 'Use organization timezone ('.$organization->timezone.')',
        ];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $options[$identifier] = $identifier;
        }

        return $options;
    }
}
