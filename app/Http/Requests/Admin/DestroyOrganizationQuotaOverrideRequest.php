<?php

namespace App\Http\Requests\Admin;

use App\Enums\QuotaMetric;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestroyOrganizationQuotaOverrideRequest extends FormRequest
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
}
