<?php

namespace App\Http\Requests;

use App\Support\ResourceQuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(ResourceQuotas $quotas): array
    {
        return [
            function (Validator $validator) use ($quotas): void {
                $user = $this->user();

                if ($user === null || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($quotas->wouldExceedOrganizationsPerUser($user)) {
                    $validator->errors()->add('name', $quotas->organizationsPerUserMessage());
                }
            },
        ];
    }
}
