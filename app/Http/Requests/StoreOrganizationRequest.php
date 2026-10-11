<?php

namespace App\Http\Requests;

use App\Support\OrganizationSubmissions;
use App\Support\ResourceQuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter an organization name.',
            'name.string' => 'Enter an organization name.',
            'name.max' => 'Organization name must be no more than :max characters.',
            'timezone.required' => 'Select a valid timezone.',
            'timezone.string' => 'Select a valid timezone.',
            'timezone.timezone' => 'Select a valid timezone.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(ResourceQuotas $quotas, OrganizationSubmissions $submissions): array
    {
        return [
            function (Validator $validator) use ($quotas, $submissions): void {
                $user = $this->user();

                if ($user === null || $validator->errors()->isNotEmpty()) {
                    return;
                }

                // A repeat of a form that already created its organization is
                // redirected to it, so it must not be refused as one too many.
                $token = $this->submissionToken();

                if ($token !== null && $submissions->organizationIdFor($user, $token) !== null) {
                    return;
                }

                if ($quotas->wouldExceedOrganizationsPerUser($user)) {
                    $validator->errors()->add('name', $quotas->organizationsPerUserMessage());
                }
            },
        ];
    }

    /**
     * The one-time token the create form was rendered with. Anything that is
     * not a UUID is ignored and the request is treated as a fresh submission.
     */
    public function submissionToken(): ?string
    {
        $token = $this->input('submission');

        return is_string($token) && Str::isUuid($token) ? $token : null;
    }
}
