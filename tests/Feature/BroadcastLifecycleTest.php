<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\InvalidBroadcastTransitionException;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_disable_and_enable_a_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-broadcasts.destroy', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The broadcast has been disabled.');

        $this->assertFalse($broadcast->refresh()->enabled);
        $this->assertFalse($broadcast->isRunnable());

        $this->actingAs($user)
            ->post(route('enabled-broadcasts.store', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The broadcast has been enabled.');

        $this->assertTrue($broadcast->refresh()->enabled);
        $this->assertTrue($broadcast->isRunnable());
    }

    public function test_an_editor_can_archive_and_restore_a_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization);
        $project = $broadcast->project;

        $this->actingAs($user)
            ->post(route('archived-broadcasts.store', [$organization, $broadcast]))
            ->assertRedirect(route('project-broadcasts.index', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been archived.');

        $this->assertTrue($broadcast->refresh()->isArchived());
        $this->assertFalse($broadcast->isRunnable());

        $this->actingAs($user)
            ->delete(route('archived-broadcasts.destroy', [$organization, $broadcast]))
            ->assertRedirect(route('project-broadcasts.index', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been restored.');

        $this->assertFalse($broadcast->refresh()->isArchived());
        $this->assertTrue($broadcast->isRunnable());
    }

    public function test_an_archived_broadcast_cannot_be_enabled_until_it_is_restored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization, ['enabled' => false, 'archived_at' => now()]);

        $this->actingAs($user)
            ->post(route('enabled-broadcasts.store', [$organization, $broadcast]))
            ->assertSessionHas('error', 'An archived broadcast cannot be enabled until it is restored.');

        $this->assertFalse($broadcast->refresh()->enabled);

        $this->expectException(InvalidBroadcastTransitionException::class);

        $broadcast->enable();
    }

    public function test_the_index_separates_archived_broadcasts_from_the_rest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization, ['name' => 'Live route']);
        Broadcast::factory()->for($broadcast->project)->archived()->create(['name' => 'Retired route']);

        $this->actingAs($user)
            ->get(route('project-broadcasts.index', [$organization, $broadcast->project]))
            ->assertOk()
            ->assertSee('Live route')
            ->assertSee('Retired route')
            ->assertSee('Archived broadcasts')
            ->assertSee('Restore');
    }

    public function test_only_enabled_and_unarchived_broadcasts_are_left_for_later_processing(): void
    {
        $project = Project::factory()->create();
        $runnable = Broadcast::factory()->for($project)->create();
        Broadcast::factory()->for($project)->disabled()->create();
        Broadcast::factory()->for($project)->archived()->create();

        $this->assertSame(
            [$runnable->id],
            $project->broadcasts()->runnable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_a_viewer_cannot_change_broadcast_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-broadcasts.destroy', [$organization, $broadcast]))
            ->assertForbidden();

        $this->assertTrue($broadcast->refresh()->enabled);
    }

    public function test_a_member_of_another_organization_cannot_change_broadcast_status(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [, $organization] = $this->memberHolding(Role::Owner);
        $broadcast = $this->broadcastIn($organization);

        $this->actingAs($outsider)
            ->delete(route('enabled-broadcasts.destroy', [$organization, $broadcast]))
            ->assertNotFound();

        $this->assertTrue($broadcast->refresh()->enabled);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function broadcastIn(Organization $organization, array $attributes = []): Broadcast
    {
        $project = Project::factory()->for($organization)->create();

        return Broadcast::factory()->for($project)->create($attributes);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
