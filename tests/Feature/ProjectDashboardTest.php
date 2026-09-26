<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_configured_project_shows_pipeline_and_activity(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 15:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $organization->forceFill(['timezone' => 'Europe/Madrid'])->save();

        $project = Project::factory()->for($organization)->active()->dailyAt('09:00', 'Europe/Madrid')->create([
            'name' => 'Pokemon TCGP News',
            'description' => 'Pocket news.',
            'next_run_at' => Carbon::parse('2026-03-16 08:00:00', 'UTC'),
        ]);

        $source = Source::factory()->for($project)->create(['name' => 'RSS Feed']);
        Source::factory()->for($project)->create(['name' => 'Archived feed', 'archived_at' => now()]);
        Destination::factory()->for($project)->create(['name' => 'Archived channel', 'archived_at' => now()]);
        $destination = Destination::factory()->for($project)->create(['name' => 'Telegram']);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'name' => 'News to Telegram',
        ]);

        $older = ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subHours(5),
            'finished_at' => now()->subHours(5)->addMinute(),
            'items_found' => 2,
            'items_delivered' => 1,
        ]);
        $lastFinishedAt = now()->subHour();
        $newer = ProjectRun::factory()->for($project)->failed()->create([
            'started_at' => $lastFinishedAt->copy()->subSeconds(30),
            'finished_at' => $lastFinishedAt,
            'items_found' => 4,
            'items_failed' => 2,
            'items_delivered' => 1,
        ]);
        ProjectRun::factory()->create([
            'started_at' => now(),
            'items_found' => 99,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]));

        $response->assertOk();
        $response->assertSee('Pokemon TCGP News');
        $response->assertSee('Pocket news.');
        $response->assertSee('Active');
        $response->assertSee('Attention needed');
        $response->assertSee('Pipeline');
        $response->assertSee('RSS Feed');
        $response->assertSee('Telegram');
        $response->assertDontSee('Archived feed');
        $response->assertDontSee('Archived channel');
        $response->assertSee('Next run');
        $response->assertSee('Europe/Madrid');
        $response->assertSee('project override');
        $response->assertSee('Failed');
        $response->assertSee($lastFinishedAt->diffForHumans());
        $response->assertSee(route('sources.edit', [$organization, $source]), false);
        $response->assertSee(route('destinations.edit', [$organization, $destination]), false);
        $response->assertSee(route('broadcast-runs.show', [$organization, $project, $newer]), false);
        $response->assertSee(route('broadcast-runs.show', [$organization, $project, $older]), false);
        $response->assertSee('discovered 4');
        $response->assertSee('discovered 2');
        $response->assertDontSee('discovered 99');
        $response->assertSee('Daily at 9:00');
        $response->assertSee('All activity');
        $response->assertSee('Edit schedule');
        $response->assertSee('Run now');
        $response->assertSee('Dry run');
        $response->assertSee('Pause');
        $response->assertSee(route('broadcasts.edit', [$organization, $project]), false);
        $response->assertDontSee('Save details');
        $response->assertDontSee('name="name"', false);
        $response->assertDontSee(route('broadcast-pipelines.index', [$organization, $project]), false);
        $response->assertDontSee('Nothing to chase yet');
        $response->assertSeeInOrder(['Pipeline', 'Add a source', 'Add destination', 'RSS Feed', 'Manage', 'Telegram', 'Manage'], false);
        $response->assertSee(route('broadcast-sources.create', [$organization, $project]), false);
        $response->assertSee(route('broadcast-destinations.create', [$organization, $project]), false);
        $this->assertLessThan(40, $queries);
    }

    public function test_a_never_run_project_lists_next_run_in_activity(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $organization->forceFill(['timezone' => 'UTC'])->save();

        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ]);
        Source::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Never run')
            ->assertSee('Next run')
            ->assertSee('UTC')
            ->assertSee('organization timezone')
            ->assertDontSee('Nothing to chase yet');
    }

    public function test_an_empty_project_guides_toward_the_pipeline(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Fresh pipeline']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Nothing to chase yet')
            ->assertSeeInOrder(['Pipeline', 'Add a source', 'Add destination', 'Nothing to chase yet'], false)
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertSee('Pipeline')
            ->assertSee('Never run')
            ->assertSee('No schedule set')
            ->assertSee('Set schedule')
            ->assertSee('All activity')
            ->assertDontSee(route('broadcast-pipelines.index', [$organization, $project]), false);
    }

    public function test_pipeline_keeps_add_actions_outside_endpoint_cards(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create(['name' => 'Only feed']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSeeInOrder(['Pipeline', 'Add a source', 'Add destination', 'Only feed', 'Manage'], false)
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertDontSee('Nothing feeding this broadcast yet.');

        Destination::factory()->for($project)->create(['name' => 'Only channel']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSeeInOrder(['Pipeline', 'Add a source', 'Add destination', 'Only feed', 'Manage', 'Only channel', 'Manage'], false)
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertDontSee('Nowhere to publish yet.');
    }

    public function test_archived_only_pipeline_endpoints_show_empty_cards_with_add_outside(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create([
            'name' => 'Old feed',
            'archived_at' => now(),
        ]);
        $destination = Destination::factory()->for($project)->create([
            'name' => 'Old channel',
            'archived_at' => now(),
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'name' => 'Legacy route',
        ]);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('Old feed')
            ->assertDontSee('Old channel')
            ->assertSee('Nothing feeding this broadcast yet.')
            ->assertSee('Nowhere to publish yet.')
            ->assertSee('Nothing to chase yet')
            ->assertSeeInOrder(['Pipeline', 'Add a source', 'Add destination'], false)
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertDontSee('Manage');
    }

    public function test_a_viewer_sees_state_but_cannot_trigger_actions(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();
        Source::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Active')
            ->assertDontSee('Run now')
            ->assertDontSee('Dry run')
            ->assertDontSee('Pause')
            ->assertDontSee('Resume')
            ->assertDontSee(route('broadcasts.edit', [$organization, $project]), false)
            ->assertDontSee('Set schedule')
            ->assertDontSee('Edit schedule')
            ->assertDontSee('Add a source')
            ->assertDontSee('Add destination');

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('paused-broadcasts.store', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_an_auto_paused_broadcast_is_called_out(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'name' => 'Fragile route',
            'enabled' => false,
            'failure_pause_reason' => 'Telegram rejected the message: chat not found',
        ]);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('A broadcast was auto-paused after repeated failures.')
            ->assertSee('Fragile route')
            ->assertSee('Telegram rejected the message: chat not found')
            ->assertSee(route('flows.edit', [$organization, $broadcast]), false);
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
