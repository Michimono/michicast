<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class OrganizationPolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may look at the organization they belong to.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $this->memberIsAtLeast($user, $organization, Role::Viewer);
    }

    /**
     * Changing name, timezone and other settings is administration.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $this->memberIsAtLeast($user, $organization, Role::Admin);
    }
}
