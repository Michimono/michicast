<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualSidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_pages_list_projects_members_and_settings_without_project_nav(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $html = $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Organization"', $html);
        $this->assertStringContainsString('>Projects</span>', $html);
        $this->assertStringContainsString('>Members</span>', $html);
        $this->assertStringContainsString('>Settings</span>', $html);
        $this->assertStringContainsString('All organizations', $html);
        $this->assertStringNotContainsString('aria-label="Project"', $html);
        $this->assertStringNotContainsString('>Overview</span>', $html);
        $this->assertStringNotContainsString('>Activity</span>', $html);
        $this->assertAnchorIsCurrent(route('organizations.show', $organization), $html);
    }

    public function test_project_pages_add_project_section_with_overview_active(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create();
        $project = Project::factory()->for($organization)->create(['name' => 'Relay Alpha']);
        Project::factory()->for($organization)->create(['name' => 'Relay Beta']);

        $html = $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Relay Alpha')
            ->assertSee('Relay Beta')
            ->getContent();

        $this->assertStringContainsString('aria-label="Organization"', $html);
        $this->assertStringContainsString('aria-label="Project"', $html);
        $this->assertStringContainsString('>Overview</span>', $html);
        $this->assertStringNotContainsString('>Broadcasts</span>', $html);
        $this->assertStringContainsString('>Sources</span>', $html);
        $this->assertStringContainsString('>Destinations</span>', $html);
        $this->assertStringContainsString('>Activity</span>', $html);
        $this->assertStringNotContainsString('>Schedule</span>', $html);
        $this->assertAnchorIsCurrent(route('projects.show', [$organization, $project]), $html);
    }

    public function test_activity_nav_is_current_on_run_history(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Viewer)->create();
        $project = Project::factory()->for($organization)->create();

        $html = $this->actingAs($user)
            ->get(route('project-runs.index', [$organization, $project]))
            ->assertOk()
            ->getContent();

        $this->assertAnchorIsCurrent(route('project-runs.index', [$organization, $project]), $html);
    }
}
