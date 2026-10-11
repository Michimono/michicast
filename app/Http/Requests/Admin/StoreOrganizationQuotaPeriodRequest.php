<?php

namespace App\Http\Requests\Admin;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrganizationQuotaPeriodRequest extends FormRequest
{
    /**
     * Form value meaning "follow the plan" (clears the organization's setting).
     */
    public const PLAN = 'plan';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'metric' => ['required', Rule::enum(QuotaMetric::class)],
            'period' => ['required', 'string', Rule::in([self::PLAN, ...array_column(QuotaPeriod::cases(), 'value')])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $metric = QuotaMetric::tryFrom((string) $this->input('metric'));

            if ($metric !== null && ! $metric->isRate()) {
                $validator->errors()->add('metric', 'Only rate metrics have a period.');
            }
        });
    }

    /**
     * Chosen period, or null to follow the plan.
     */
    public function resolvedPeriod(): ?QuotaPeriod
    {
        return QuotaPeriod::tryFrom($this->string('period')->toString());
    }
}
