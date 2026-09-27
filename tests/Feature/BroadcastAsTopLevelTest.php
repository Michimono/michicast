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

class BroadcastAsTopLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_dashboard_lists_broadcast_cards_with_antenna(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Morning Digest']);

        $html = $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Morning Digest')
            ->assertSee('+ New broadcast')
            ->assertSee('View broadcast')
            ->assertSee('Your broadcasts at a glance')
            ->assertDontSee('+ New project')
            ->assertDontSee('View project')
            ->getContent();

        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'M12 21v-8'));
        $this->assertStringContainsString('>Broadcast</span>', $html);
        $this->assertStringContainsString(route('broadcasts.show', [$organization, $project]), $html);
    }

    public function test_creating_a_broadcast_uses_broadcast_routes_not_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertOk()
            ->assertSee('New broadcast')
            ->assertSee('Broadcast name')
            ->assertDontSee('New project');

        $response = $this->actingAs($user)->post(route('broadcasts.store', $organization), [
            'name' => 'Evening Relay',
            'description' => 'Quiet nightly pass.',
            'frequency' => 'daily',
            'time' => '09:00',
            'timezone' => '',
        ]);

        $project = $organization->projects()->sole();

        $response->assertRedirect(route('broadcasts.show', [$organization, $project]));
        $response->assertSessionHas('success', 'The broadcast has been created.');
    }

    public function test_overview_and_pipeline_live_under_broadcast_routes(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Relay']);
        $source = Source::factory()->for($project)->create(['name' => 'Feed']);
        $destination = Destination::factory()->for($project)->create(['name' => 'Channel']);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Relay')
            ->assertSee('Pipeline')
            ->assertSee('Feed')
            ->assertSee('Channel')
            ->assertDontSee('Add a source')
            ->assertDontSee('Add destination')
            ->assertDontSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertDontSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-runs.index', [$organization, $project]), false);

        $this->actingAs($user)
            ->get('/organizations/'.$organization->getKey().'/projects/'.$project->getKey())
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));
    }

    public function test_soft_one_source_and_one_destination_per_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create();
        Destination::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('broadcast-sources.create', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error');

        $this->assertSame(1, $project->sources()->notArchived()->count());
        $this->assertSame(1, $project->destinations()->notArchived()->count());
    }

    public function test_schedule_and_activity_routes_remain_on_the_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create();

        $this->actingAs($user)
            ->get(route('broadcast-schedules.edit', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]));

        $this->actingAs($user)
            ->get(route('broadcast-runs.index', [$organization, $project]))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('paused-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been paused.');
    }

    public function test_broadcasts_are_isolated_across_organizations(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $foreign = Project::factory()->create(['name' => 'Foreign Digest']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $foreign]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertDontSee('Foreign Digest');
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
