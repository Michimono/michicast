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

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $broadcast = Broadcast::factory()->create();
        $project = $broadcast->project;
        $organization = $project->organization;

        $this->get(route('project-broadcasts.index', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->get(route('project-broadcasts.create', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->post(route('project-broadcasts.store', [$organization, $project]), [])->assertRedirect(route('session.create'));
        $this->get(route('broadcasts.edit', [$organization, $broadcast]))->assertRedirect(route('session.create'));
        $this->patch(route('broadcasts.update', [$organization, $broadcast]), [])->assertRedirect(route('session.create'));
    }

    public function test_the_index_lists_the_broadcasts_of_this_project_only(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        Broadcast::factory()->for($project)->create(['name' => 'RSS to Telegram']);
        Broadcast::factory()->for(Project::factory()->for($organization))->create(['name' => 'Other project route']);
        Broadcast::factory()->create(['name' => 'Someone else route']);

        $this->actingAs($user)
            ->get(route('project-broadcasts.index', [$organization, $project]))
            ->assertOk()
            ->assertSee('RSS to Telegram')
            ->assertDontSee('Other project route')
            ->assertDontSee('Someone else route')
            ->assertDontSee('New broadcast');
    }

    public function test_an_editor_can_add_a_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['name' => 'Pocket feed']);
        $destination = Destination::factory()->for($project)->create(['name' => 'News channel']);

        $response = $this->actingAs($user)->post(route('project-broadcasts.store', [$organization, $project]), [
            'name' => 'RSS to Telegram',
            'source_id' => $source->id,
            'destination_id' => $destination->id,
        ]);

        $broadcast = $project->broadcasts()->sole();

        $response->assertRedirect(route('projects.show', [$organization, $project]));
        $response->assertSessionHas('success', 'The pipeline has been connected.');

        $this->assertSame('RSS to Telegram', $broadcast->name);
        $this->assertSame($source->id, $broadcast->source_id);
        $this->assertSame($destination->id, $broadcast->destination_id);
        $this->assertTrue($broadcast->enabled);
        $this->assertTrue($broadcast->isRunnable());
        $this->assertNull($broadcast->filter);
        $this->assertNull($broadcast->transform);
    }

    public function test_a_source_from_another_project_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->for($project)->create();
        $foreignSource = Source::factory()->for(Project::factory()->for($organization))->create();

        $this->actingAs($user)
            ->post(route('project-broadcasts.store', [$organization, $project]), [
                'name' => 'Cross project',
                'source_id' => $foreignSource->id,
                'destination_id' => $destination->id,
            ])
            ->assertSessionHasErrors('source_id');

        $this->assertSame(0, $project->broadcasts()->count());
    }

    public function test_a_destination_from_another_project_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $foreignDestination = Destination::factory()->for(Project::factory()->for($organization))->create();

        $this->actingAs($user)
            ->post(route('project-broadcasts.store', [$organization, $project]), [
                'name' => 'Cross project',
                'source_id' => $source->id,
                'destination_id' => $foreignDestination->id,
            ])
            ->assertSessionHasErrors('destination_id');

        $this->assertSame(0, $project->broadcasts()->count());
    }

    public function test_an_archived_source_cannot_be_chosen_for_a_new_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->archived()->create();
        $destination = Destination::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('project-broadcasts.store', [$organization, $project]), [
                'name' => 'Retired feed',
                'source_id' => $source->id,
                'destination_id' => $destination->id,
            ])
            ->assertSessionHasErrors('source_id');
    }

    public function test_an_editor_can_rename_and_reroute_a_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = $this->broadcastIn($organization, ['name' => 'RSS to Telegram']);
        $project = $broadcast->project;
        $otherSource = Source::factory()->for($project)->create(['name' => 'Site scrape']);
        $otherDestination = Destination::factory()->for($project)->create(['name' => 'Alerts']);

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('RSS to Telegram');

        $this->actingAs($user)
            ->patch(route('broadcasts.update', [$organization, $broadcast]), [
                'name' => 'Site to Alerts',
                'source_id' => $otherSource->id,
                'destination_id' => $otherDestination->id,
            ])
            ->assertRedirect(route('broadcasts.edit', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The broadcast has been updated.');

        $broadcast->refresh();

        $this->assertSame('Site to Alerts', $broadcast->name);
        $this->assertSame($otherSource->id, $broadcast->source_id);
        $this->assertSame($otherDestination->id, $broadcast->destination_id);
    }

    public function test_a_viewer_cannot_add_or_change_a_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $broadcast = $this->broadcastIn($organization);
        $project = $broadcast->project;

        $this->actingAs($user)->get(route('project-broadcasts.create', [$organization, $project]))->assertForbidden();
        $this->actingAs($user)->post(route('project-broadcasts.store', [$organization, $project]), [
            'name' => 'RSS to Telegram',
            'source_id' => $broadcast->source_id,
            'destination_id' => $broadcast->destination_id,
        ])->assertForbidden();
        $this->actingAs($user)->get(route('broadcasts.edit', [$organization, $broadcast]))->assertForbidden();
        $this->actingAs($user)->patch(route('broadcasts.update', [$organization, $broadcast]), [
            'name' => 'Renamed',
            'source_id' => $broadcast->source_id,
            'destination_id' => $broadcast->destination_id,
        ])->assertForbidden();
    }

    public function test_a_broadcast_of_another_organization_is_out_of_reach(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $elsewhere = Broadcast::factory()->create(['name' => 'Someone else route']);

        $this->actingAs($user)->get(route('broadcasts.edit', [$organization, $elsewhere]))->assertNotFound();
        $this->actingAs($user)->patch(route('broadcasts.update', [$organization, $elsewhere]), [
            'name' => 'Hijacked',
            'source_id' => $elsewhere->source_id,
            'destination_id' => $elsewhere->destination_id,
        ])->assertNotFound();
    }

    public function test_overview_does_not_link_to_the_broadcasts_index(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Pipeline')
            ->assertDontSee(route('project-broadcasts.index', [$organization, $project]), false);
    }

    public function test_the_create_screen_asks_for_sources_and_destinations_when_missing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('project-broadcasts.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('Need both ends first');
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
