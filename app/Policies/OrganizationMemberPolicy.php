<?php

namespace App\Policies;

use App\Models\OrganizationMember;
use App\Models\User;

class OrganizationMemberPolicy
{
    /**
     * Owners and admins change roles; editors and viewers may not.
     */
    public function update(User $user, OrganizationMember $member): bool
    {
        return $member->organization->roleFor($user)?->managesMembers() ?? false;
    }
}
