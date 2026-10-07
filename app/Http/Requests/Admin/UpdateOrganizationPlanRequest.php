<?php

namespace App\Http\Requests\Admin;

use App\Support\Quotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationPlanRequest extends FormRequest
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
            'plan' => ['required', 'string', Rule::in(app(Quotas::class)->knownPlanNames())],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
