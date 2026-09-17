<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class BroadcastPolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may list the broadcasts of a project they can see.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Viewer);
    }

    /**
     * Editors and above add a broadcast to a project.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above rename a broadcast and change its route.
     */
    public function update(User $user, Broadcast $broadcast): bool
    {
        return $this->memberIsAtLeast($user, $broadcast->project->organization, Role::Editor);
    }

    /**
     * Editors and above enable, disable, archive and restore a broadcast.
     */
    public function changeStatus(User $user, Broadcast $broadcast): bool
    {
        return $this->memberIsAtLeast($user, $broadcast->project->organization, Role::Editor);
    }
}
