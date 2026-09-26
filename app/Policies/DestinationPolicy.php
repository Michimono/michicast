<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class DestinationPolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may list the destinations of a project they can see.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Viewer);
    }

    /**
     * Editors and above add a destination to a project.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above rename a destination and change its settings.
     */
    public function update(User $user, Destination $destination): bool
    {
        return $this->memberIsAtLeast($user, $destination->project->organization, Role::Editor);
    }

    /**
     * Editors and above may delete a destination.
     */
    public function delete(User $user, Destination $destination): bool
    {
        return $this->memberIsAtLeast($user, $destination->project->organization, Role::Editor);
    }

    /**
     * Editors and above may send a destination connection test.
     */
    public function testConnection(User $user, Destination $destination): bool
    {
        return $this->memberIsAtLeast($user, $destination->project->organization, Role::Editor);
    }
}
