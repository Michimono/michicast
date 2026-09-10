<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member may look at the organization and who belongs to it.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $organization->roleFor($user) !== null;
    }
}
