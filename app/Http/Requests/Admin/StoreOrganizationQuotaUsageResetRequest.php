<?php

namespace App\Http\Requests\Admin;

use App\Enums\QuotaMetric;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrganizationQuotaUsageResetRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $raw = $this->input('metric');

            if (! is_string($raw) || $raw === '') {
                return;
            }

            $metric = QuotaMetric::tryFrom($raw);

            if ($metric === null) {
                return;
            }

            if (! $metric->isRate()) {
                $validator->errors()->add('metric', 'Only rate metrics can have their current-period usage reset.');
            }
        });
    }
}
