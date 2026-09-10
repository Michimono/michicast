<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class OrganizationMemberPolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may see who else belongs.
     */
    public function view(User $user, OrganizationMember $member): bool
    {
        return $this->memberIsAtLeast($user, $member->organization, Role::Viewer);
    }

    /**
     * Owners and admins change roles; editors and viewers may not.
     */
    public function update(User $user, OrganizationMember $member): bool
    {
        return $this->memberIsAtLeast($user, $member->organization, Role::Admin);
    }

    /**
     * A member may leave. Owners and admins may remove someone else.
     */
    public function delete(User $user, OrganizationMember $member): bool
    {
        if ($user->is($member->user)) {
            return true;
        }

        return $this->memberIsAtLeast($user, $member->organization, Role::Admin);
    }
}
