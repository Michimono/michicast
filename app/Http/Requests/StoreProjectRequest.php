<?php

namespace App\Http\Requests;

use App\Enums\ProjectKind;
use App\Models\Project;
use App\Support\CurrentOrganization;
use App\Support\ProjectScheduleFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(CurrentOrganization $current): bool
    {
        return $this->user()?->can('create', [Project::class, $current->organization()]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', Rule::enum(ProjectKind::class)],
        ];

        if ($this->filled('frequency')) {
            $rules = array_merge($rules, ProjectScheduleFields::rules());
        }

        return $rules;
    }

    /**
     * Simple unless the form chose the visual canvas.
     */
    public function kind(): ProjectKind
    {
        return ProjectKind::tryFrom($this->string('kind')->toString()) ?? ProjectKind::Simple;
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
