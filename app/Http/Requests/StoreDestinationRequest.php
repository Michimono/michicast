<?php

namespace App\Http\Requests;

use App\Enums\QuotaMetric;
use App\Http\Requests\Concerns\ValidatesDestinationSettings;
use App\Models\Destination;
use App\Models\Project;
use App\Support\DestinationProviderRegistry;
use App\Support\ResourceQuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDestinationRequest extends FormRequest
{
    use ValidatesDestinationSettings;

    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        return $this->user()?->can('create', [Destination::class, $project]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(DestinationProviderRegistry $registry): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in($registry->selectableTypes())],
            'config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            ...$this->configurationRules($this->type()),
            ...$this->credentialRules($this->type()),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(ResourceQuotas $quotas): array
    {
        return [
            function (Validator $validator) use ($quotas): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $project = $this->route('project');

                if (! $project instanceof Project) {
                    return;
                }

                $organization = $project->organization;

                if ($quotas->wouldExceed($organization, QuotaMetric::Destinations)) {
                    $validator->errors()->add(
                        'name',
                        $quotas->message($organization, QuotaMetric::Destinations),
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->settingAttributes($this->type());
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    public function type(): string
    {
        return $this->string('type')->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return $this->configurationFor($this->type());
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return $this->credentialsFor($this->type());
    }
}
