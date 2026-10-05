<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RequiresConfirmation;
use App\Models\OrganizationMember;
use Illuminate\Foundation\Http\FormRequest;

class DestroyMemberRequest extends FormRequest
{
    use RequiresConfirmation;

    public function authorize(): bool
    {
        $member = $this->route('member');

        if (! $member instanceof OrganizationMember) {
            return false;
        }

        return $this->user()?->can('delete', $member) ?? false;
    }

    protected function confirmationSubject(): string
    {
        return 'remove this member';
    }
}
