<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class StoreTwoFactorRecoveryCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasTwoFactorEnabled() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! Hash::check($this->string('password')->toString(), $this->user()->password)) {
                    $validator->errors()->add('password', __('auth.password'));
                }
            },
        ];
    }
}
