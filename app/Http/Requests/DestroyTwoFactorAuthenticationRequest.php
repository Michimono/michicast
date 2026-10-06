<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class DestroyTwoFactorAuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->hasTwoFactorEnabled() || $user->hasTwoFactorPending());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->user()?->hasTwoFactorPending()) {
            return [];
        }

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

                if ($this->user()?->hasTwoFactorPending()) {
                    return;
                }

                if (! Hash::check($this->string('password')->toString(), $this->user()->password)) {
                    $validator->errors()->add('password', __('auth.password'));
                }
            },
        ];
    }
}
