<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_delete_a_destination_and_free_the_pipeline_slot(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create(['name' => 'Channel']);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('destinations.destroy', [$organization, $destination]), ['confirmation' => '1'])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The destination has been deleted.');

        $this->assertDatabaseMissing('destinations', ['id' => $destination->getKey()]);
        $this->assertSame(0, $project->broadcasts()->count());
        $this->assertSame(0, $project->destinations()->count());

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Add destination')
            ->assertDontSee('Channel')
            ->getContent();

        $this->assertStringContainsString(route('broadcast-destinations.create', [$organization, $project]), $html);
    }

    public function test_destination_edit_offers_delete_not_archive_or_disable(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = $this->destinationIn($organization);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Delete destination')
            ->assertDontSee('>Archive</button>', false)
            ->assertDontSee('>Disable</button>', false)
            ->assertDontSee('>Enable</button>', false)
            ->assertDontSee('>Restore</button>', false)
            ->assertDontSee('Enabled')
            ->assertDontSee('Disabled')
            ->assertDontSee('name="enabled"', false);
    }

    public function test_destination_list_routes_are_gone(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get('/organizations/'.$organization->getKey().'/broadcasts/'.$project->getKey().'/destinations')
            ->assertMethodNotAllowed();
    }

    public function test_only_unarchived_destinations_are_left_for_later_processing(): void
    {
        $project = Project::factory()->create();
        $runnable = Destination::factory()->for($project)->create();
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

    public function test_a_viewer_cannot_delete_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $destination = $this->destinationIn($organization);

        $this->actingAs($user)
            ->delete(route('destinations.destroy', [$organization, $destination]), ['confirmation' => '1'])
            ->assertForbidden();

        $this->assertDatabaseHas('destinations', ['id' => $destination->getKey()]);
    }

    public function test_a_member_of_another_organization_cannot_delete_a_destination_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [, $organization] = $this->memberHolding(Role::Owner);
        $destination = $this->destinationIn($organization);

        $this->actingAs($outsider)
            ->delete(route('destinations.destroy', [$organization, $destination]), ['confirmation' => '1'])
            ->assertNotFound();

        $this->assertDatabaseHas('destinations', ['id' => $destination->getKey()]);
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
