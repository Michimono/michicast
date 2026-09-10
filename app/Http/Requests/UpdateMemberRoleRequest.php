<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\OrganizationMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /**
     * Checked before validation so an editor or viewer is refused outright.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->member()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->demotesTheLastOwner()) {
                    $validator->errors()->add(
                        'role',
                        'This is the last owner. Give another member the owner role first.',
                    );
                }
            },
        ];
    }

    private function demotesTheLastOwner(): bool
    {
        $role = $this->enum('role', Role::class);
        $member = $this->member();

        if ($role === null || $role === Role::Owner || ! $member instanceof OrganizationMember) {
            return false;
        }

        return $member->isLastOwner();
    }

    private function member(): ?OrganizationMember
    {
        $member = $this->route('member');

        return $member instanceof OrganizationMember ? $member : null;
    }
}
