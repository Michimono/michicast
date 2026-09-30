<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_project_starts_as_draft(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('broadcasts.store', $organization), ['name' => 'News'])
            ->assertSessionHasNoErrors();

        $project = $organization->projects()->sole();

        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertFalse($project->isDispatchable());
    }

    public function test_an_editor_can_activate_a_draft_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Broadcast::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('active-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been activated.');

        $project->refresh();

        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertTrue($project->isDispatchable());
    }

    public function test_a_draft_without_an_enabled_broadcast_cannot_be_activated(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('active-broadcasts.store', [$organization, $project]))
            ->assertSessionHas('error', 'A project cannot be activated until it has at least one enabled broadcast.');

        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    public function test_an_editor_can_pause_an_active_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('paused-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been paused.');

        $project->refresh();

        $this->assertSame(ProjectStatus::Paused, $project->status);
        $this->assertFalse($project->isDispatchable());
    }

    public function test_an_editor_can_resume_a_paused_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->paused()->create();

        $this->actingAs($user)
            ->delete(route('paused-broadcasts.destroy', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been resumed.');

        $project->refresh();

        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertTrue($project->isDispatchable());
    }

    #[DataProvider('archivableFactoryStates')]
    public function test_an_editor_can_archive_a_project(string $state): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = $this->projectInState($organization, $state);
        $previous = $project->status;

        $this->actingAs($user)
            ->post(route('archived-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been archived.');

        $project->refresh();

        $this->assertSame(ProjectStatus::Archived, $project->status);
        $this->assertSame($previous, $project->archived_from);
        $this->assertFalse($project->isDispatchable());
    }

    public function test_restoring_an_archived_project_returns_it_to_its_previous_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->archived(ProjectStatus::Active)->create();

        $this->actingAs($user)
            ->delete(route('archived-broadcasts.destroy', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been restored.');

        $project->refresh();

        $this->assertSame(ProjectStatus::Active, $project->status);
        $this->assertNull($project->archived_from);
        $this->assertTrue($project->isDispatchable());
    }

    public function test_the_default_listing_excludes_archived_projects(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $visible = Project::factory()->for($organization)->create(['name' => 'Live Pipeline']);
        Project::factory()->for($organization)->archived()->create(['name' => 'Old Pipeline']);

        $this->actingAs($user)
            ->get(route('broadcasts.index', $organization))
            ->assertOk()
            ->assertSee('Live Pipeline')
            ->assertDontSee('Old Pipeline')
            ->assertSee(route('broadcasts.show', [$organization, $visible]), false)
            ->assertSee('Draft');

        $this->assertSame(
            [$visible->id],
            $organization->projects()->notArchived()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_an_archived_project_remains_readable_by_url(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->archived()->create(['name' => 'Old Pipeline']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Old Pipeline')
            ->assertSee('Archived')
            ->assertDontSee('Play')
            ->assertDontSee('Restore');
    }

    public function test_an_editor_is_offered_the_transitions_that_apply(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Broadcast::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Play')
            ->assertSee(route('active-broadcasts.store', [$organization, $project]), false)
            ->assertDontSee('>Archive</button>', false)
            ->assertDontSee('Pause')
            ->assertDontSee('Resume')
            ->assertDontSee('Restore')
            ->assertSee('Settings');

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Activate')
            ->assertSee('Archive')
            ->assertSee(route('archived-broadcasts.store', [$organization, $project]), false)
            ->assertDontSee('Restore');
    }

    public function test_a_viewer_cannot_change_project_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('active-broadcasts.store', [$organization, $project]))
            ->assertForbidden();

        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    public function test_guests_cannot_change_project_status(): void
    {
        $project = Project::factory()->create();

        $this->post(route('active-broadcasts.store', [$project->organization, $project]))
            ->assertRedirect(route('session.create'));

        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    public function test_a_member_of_another_organization_cannot_change_status_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($outsider)
            ->post(route('active-broadcasts.store', [$organization, $project]))
            ->assertNotFound();

        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    #[DataProvider('invalidTransitions')]
    public function test_an_invalid_transition_is_rejected_with_a_clear_message(
        string $method,
        string $route,
        string $state,
        string $message,
    ): void {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = $this->projectInState($organization, $state);
        $status = $project->status;

        $this->actingAs($user)
            ->{$method}(route($route, [$organization, $project]))
            ->assertRedirect()
            ->assertSessionHas('error', $message);

        $this->assertSame($status, $project->refresh()->status);
    }

    public function test_only_an_active_project_is_dispatchable(): void
    {
        $active = Project::factory()->active()->create();
        Project::factory()->create();
        Project::factory()->paused()->create();
        Project::factory()->archived()->create();

        $this->assertTrue($active->isDispatchable());
        $this->assertSame(
            [$active->id],
            Project::query()->dispatchable()->orderBy('id')->pluck('id')->all(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function archivableFactoryStates(): array
    {
        return [
            'draft' => ['draft'],
            'active' => ['active'],
            'paused' => ['paused'],
        ];
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'activate paused' => ['post', 'active-broadcasts.store', 'paused', 'A project can only be activated from draft.'],
            'activate archived' => ['post', 'active-broadcasts.store', 'archived', 'An archived project cannot be activated without being restored.'],
            'pause draft' => ['post', 'paused-broadcasts.store', 'draft', 'A project can only be paused while it is active.'],
            'resume draft' => ['delete', 'paused-broadcasts.destroy', 'draft', 'A project can only be resumed while it is paused.'],
            'archive archived' => ['post', 'archived-broadcasts.store', 'archived', 'An archived project cannot be archived again.'],
            'restore draft' => ['delete', 'archived-broadcasts.destroy', 'draft', 'Only an archived project can be restored.'],
        ];
    }

    private function projectInState(Organization $organization, string $state): Project
    {
        $factory = Project::factory()->for($organization);

        return match ($state) {
            'active' => $factory->active()->create(),
            'paused' => $factory->paused()->create(),
            'archived' => $factory->archived()->create(),
            default => $factory->create(),
        };
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
