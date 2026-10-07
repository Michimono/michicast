<?php

namespace App\Policies\Concerns;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

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
 *
 * When the organization is suspended, viewer-level reads still pass. Anything
 * that asks for a minimum above viewer is refused with a readable message.
 */
trait ChecksOrganizationRole
{
    protected function memberIsAtLeast(User $user, Organization $organization, Role $minimum): bool
    {
        if (! ($organization->roleFor($user)?->atLeast($minimum) ?? false)) {
            return false;
        }

        if ($minimum !== Role::Viewer && $organization->isSuspended()) {
            throw new AuthorizationException(
                'This organization is suspended. You can still view it, but changes are disabled until an admin reinstates it.',
            );
        }

        return true;
    }
}
