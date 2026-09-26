<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class SourcePolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may list the sources of a project they can see.
     */
    public function viewAny(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Viewer);
    }

    /**
     * Editors and above add a source to a project.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above rename a source and change its configuration.
     */
    public function update(User $user, Source $source): bool
    {
        return $this->memberIsAtLeast($user, $source->project->organization, Role::Editor);
    }

    /**
     * Editors and above may delete a source.
     */
    public function delete(User $user, Source $source): bool
    {
        return $this->memberIsAtLeast($user, $source->project->organization, Role::Editor);
    }

    /**
     * Editors and above may test a source against its provider.
     */
    public function testConnection(User $user, Source $source): bool
    {
        return $this->memberIsAtLeast($user, $source->project->organization, Role::Editor);
    }
}
