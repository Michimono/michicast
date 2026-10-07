<?php

namespace App\Http\Requests;

use App\Support\CurrentOrganization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateOrganizationSettingRequest extends FormRequest
{
    /**
     * Checked before validation so an editor or viewer is refused outright.
     */
    public function authorize(CurrentOrganization $current): bool
    {
        Gate::authorize('update', $current->organization());

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
}
