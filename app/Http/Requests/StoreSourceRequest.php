<?php

namespace App\Http\Requests;

use App\Enums\QuotaMetric;
use App\Http\Requests\Concerns\ValidatesSourceConfiguration;
use App\Models\Project;
use App\Models\Source;
use App\Support\ResourceQuotas;
use App\Support\SourceProviderRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
{
    use ValidatesSourceConfiguration;

    /**
     * Checked before validation so a viewer is refused outright.
     */
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        return $this->user()?->can('create', [Source::class, $project]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(SourceProviderRegistry $registry): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in($registry->types())],
            'config' => ['nullable', 'array'],
            ...$this->configurationRules($this->type()),
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

                if ($quotas->wouldExceed($organization, QuotaMetric::Sources)) {
                    $validator->errors()->add(
                        'name',
                        $quotas->message($organization, QuotaMetric::Sources),
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
        return $this->configurationAttributes($this->type());
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
}
