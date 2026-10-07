<?php

namespace App\Http\Requests\Admin;

use App\Enums\QuotaMetric;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrganizationQuotaOverrideRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'min:0'],
            'unlimited' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('unlimited')) {
                return;
            }

            if ($this->input('limit') === null || $this->input('limit') === '') {
                $validator->errors()->add('limit', 'Provide a non-negative limit or mark unlimited.');
            }
        });
    }

    /**
     * Resolved override limit: null when unlimited.
     */
    public function resolvedLimit(): ?int
    {
        if ($this->boolean('unlimited')) {
            return null;
        }

        return (int) $this->input('limit');
    }
}
