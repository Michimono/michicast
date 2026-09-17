<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\InvalidDestinationTransitionException;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_disable_and_enable_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = $this->destinationIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-destinations.destroy', [$organization, $destination]))
            ->assertSessionHas('success', 'The destination has been disabled.');

        $this->assertFalse($destination->refresh()->enabled);
        $this->assertFalse($destination->isRunnable());

        $this->actingAs($user)
            ->post(route('enabled-destinations.store', [$organization, $destination]))
            ->assertSessionHas('success', 'The destination has been enabled.');

        $this->assertTrue($destination->refresh()->enabled);
        $this->assertTrue($destination->isRunnable());
    }

    public function test_an_editor_can_archive_and_restore_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = $this->destinationIn($organization);
        $project = $destination->project;

        $this->actingAs($user)
            ->post(route('archived-destinations.store', [$organization, $destination]))
            ->assertRedirect(route('project-destinations.index', [$organization, $project]))
            ->assertSessionHas('success', 'The destination has been archived.');

        $this->assertTrue($destination->refresh()->isArchived());
        $this->assertFalse($destination->isRunnable());

        $this->actingAs($user)
            ->delete(route('archived-destinations.destroy', [$organization, $destination]))
            ->assertRedirect(route('project-destinations.index', [$organization, $project]))
            ->assertSessionHas('success', 'The destination has been restored.');

        $this->assertFalse($destination->refresh()->isArchived());
        $this->assertTrue($destination->isRunnable());
    }

    public function test_an_archived_destination_cannot_be_enabled_until_it_is_restored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = $this->destinationIn($organization, ['enabled' => false, 'archived_at' => now()]);

        $this->actingAs($user)
            ->post(route('enabled-destinations.store', [$organization, $destination]))
            ->assertSessionHas('error', 'An archived destination cannot be enabled until it is restored.');

        $this->assertFalse($destination->refresh()->enabled);

        $this->expectException(InvalidDestinationTransitionException::class);

        $destination->enable();
    }

    public function test_only_enabled_and_unarchived_destinations_are_left_for_later_processing(): void
    {
        $project = Project::factory()->create();
        $runnable = Destination::factory()->for($project)->create();
        Destination::factory()->for($project)->disabled()->create();
        Destination::factory()->for($project)->archived()->create();

        $this->assertSame(
            [$runnable->id],
            $project->destinations()->runnable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_a_destination_describes_itself_to_its_provider(): void
    {
        $destination = Destination::factory()->create([
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'super-secret-token'],
        ]);

        $definition = $destination->toDefinition();

        $this->assertSame('recording', $definition->type);
        $this->assertSame($destination->project_id, $definition->projectId);
        $this->assertSame($destination->id, $definition->destinationId);
        $this->assertSame('updates', $definition->config('channel'));
        $this->assertSame('super-secret-token', $definition->credential('token'));
    }

    public function test_a_viewer_cannot_change_a_destination_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $destination = $this->destinationIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-destinations.destroy', [$organization, $destination]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('archived-destinations.store', [$organization, $destination]))
            ->assertForbidden();

        $this->assertTrue($destination->refresh()->isRunnable());
    }

    public function test_a_member_of_another_organization_cannot_change_a_destination_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [, $organization] = $this->memberHolding(Role::Owner);
        $destination = $this->destinationIn($organization);

        $this->actingAs($outsider)
            ->post(route('archived-destinations.store', [$organization, $destination]))
            ->assertNotFound();

        $this->assertFalse($destination->refresh()->isArchived());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function destinationIn(Organization $organization, array $attributes = []): Destination
    {
        return Destination::factory()
            ->for(Project::factory()->for($organization))
            ->create($attributes);
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
