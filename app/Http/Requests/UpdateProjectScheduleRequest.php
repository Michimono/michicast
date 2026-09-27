<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\CurrentOrganization;
use App\Support\ProjectScheduleFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return ProjectScheduleFields::rules();
    }

    /**
     * @return array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}
     */
    public function schedule(): array
    {
        return ProjectScheduleFields::schedule($this);
    }

    public function timezoneOverride(): ?string
    {
        return ProjectScheduleFields::timezoneOverride($this);
    }

    public function effectiveTimezone(Project $project): string
    {
        return $this->timezoneOverride() ?? $project->organization->timezone;
    }

    public function timezoneSource(Project $project): string
    {
        return $this->timezoneOverride() === null
            ? 'organization timezone'
            : 'broadcast override';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ProjectScheduleFields::messages();
    }

    protected function prepareForValidation(): void
    {
        ProjectScheduleFields::prepare($this);
    }
}
