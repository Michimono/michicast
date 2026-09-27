<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Support\CurrentOrganization;
use App\Support\ProjectScheduleFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->filled('frequency')) {
            $rules = array_merge($rules, ProjectScheduleFields::rules());
        }

        return $rules;
    }

    public function nullableDescription(): ?string
    {
        $description = $this->string('description')->trim()->toString();

        return $description === '' ? null : $description;
    }

    public function hasSchedule(): bool
    {
        return $this->filled('frequency');
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
