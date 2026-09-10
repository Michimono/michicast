<?php

namespace App\Policies\Concerns;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

/**
 * The one way policies ask what a user may do in an organization.
 *
 * Every organization-scoped policy in this application follows the same shape:
 * find the organization the record belongs to, then require a minimum role.
 * Roles rank viewer, editor, admin, owner, so a method asks for the lowest role
 * that should be allowed and everything above it follows.
 *
 *     public function update(User $user, Project $project): bool
 *     {
 *         return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
 *     }
 *
 * Two rules hold everywhere. A non-member is denied whatever role they hold in
 * another organization, because the role is only ever read from the record's
 * own organization. And authorization fails closed: a policy method that does
 * not exist denies the action, so new abilities are unavailable until a method
 * grants them. Do not read the current organization here — a policy answers for
 * the record it is given.
 */
trait ChecksOrganizationRole
{
    protected function memberIsAtLeast(User $user, Organization $organization, Role $minimum): bool
    {
        return $organization->roleFor($user)?->atLeast($minimum) ?? false;
    }
}
