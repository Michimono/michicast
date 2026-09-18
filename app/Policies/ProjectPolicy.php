<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationRole;

class ProjectPolicy
{
    use ChecksOrganizationRole;

    /**
     * Every member may list projects in an organization they belong to.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->memberIsAtLeast($user, $organization, Role::Viewer);
    }

    /**
     * Every member may read a project in an organization they belong to.
     */
    public function view(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Viewer);
    }

    /**
     * Editors and above create projects.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->memberIsAtLeast($user, $organization, Role::Editor);
    }

    /**
     * Editors and above rename and describe a project.
     */
    public function update(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above activate, pause, resume, archive and restore a project.
     */
    public function changeStatus(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above queue a project for the next dispatcher tick.
     */
    public function markDue(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }

    /**
     * Editors and above may dry-run a project without publishing.
     */
    public function dryRun(User $user, Project $project): bool
    {
        return $this->memberIsAtLeast($user, $project->organization, Role::Editor);
    }
}
