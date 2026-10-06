<?php

namespace App\Http\Requests;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Support\CurrentOrganization;
use App\Support\ResourceQuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    /**
     * Checked before validation so an editor or viewer is refused outright.
     */
    public function authorize(CurrentOrganization $current): bool
    {
        return $this->user()?->can('create', [OrganizationInvitation::class, $current->organization()]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(ResourceQuotas $quotas): array
    {
        return [
            function (Validator $validator) use ($quotas): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                $email = $this->normalizedEmail();
                $organization = $this->organization();

                if ($this->alreadyMember($organization, $email)) {
                    $validator->errors()->add(
                        'email',
                        'That address is already a member of this organization.',
                    );

                    return;
                }

                // Replacing a pending invite for the same address does not grow the count.
                if ($this->hasPendingInvitation($organization, $email)) {
                    return;
                }

                if ($quotas->wouldExceed($organization, QuotaMetric::Members)) {
                    $validator->errors()->add(
                        'email',
                        $quotas->message($organization, QuotaMetric::Members),
                    );
                }
            },
        ];
    }

    public function normalizedEmail(): string
    {
        return strtolower($this->string('email')->toString());
    }

    private function alreadyMember(Organization $organization, string $email): bool
    {
        return $organization->members()
            ->whereHas('user', fn (Builder $query) => $query->whereRaw('LOWER(email) = ?', [$email]))
            ->exists();
    }

    private function hasPendingInvitation(Organization $organization, string $email): bool
    {
        return $organization->invitations()
            ->pending()
            ->where('email', $email)
            ->exists();
    }

    private function organization(): Organization
    {
        return $this->container->make(CurrentOrganization::class)->organization();
    }
}
