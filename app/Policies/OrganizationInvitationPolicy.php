<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class OrganizationInvitationPolicy
{
    use ChecksOrganizationRole;

    /**
     * Owners and admins send invitations.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->memberIsAtLeast($user, $organization, Role::Admin);
    }

    /**
     * Owners and admins cancel invitations for the organization they belong to.
     */
    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return $this->memberIsAtLeast($user, $invitation->organization, Role::Admin);
    }
}
