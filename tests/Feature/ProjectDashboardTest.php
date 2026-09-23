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

    public function test_a_configured_project_shows_status_counts_and_recent_runs(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 15:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $organization->forceFill(['timezone' => 'Europe/Madrid'])->save();

        $project = Project::factory()->for($organization)->active()->dailyAt('09:00', 'Europe/Madrid')->create([
            'name' => 'Pokemon TCGP News',
            'next_run_at' => Carbon::parse('2026-03-16 08:00:00', 'UTC'),
        ]);

        Source::factory()->for($project)->create();
        Source::factory()->for($project)->create();
        Source::factory()->for($project)->create(['archived_at' => now()]);
        Destination::factory()->for($project)->create();
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $project->sources()->notArchived()->value('id'),
            'destination_id' => $project->destinations()->notArchived()->value('id'),
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
            ->get(route('projects.show', [$organization, $project]));

        $response->assertOk();
        $response->assertSee('Pokemon TCGP News');
        $response->assertSee('Active');
        $response->assertSee('Europe/Madrid');
        $response->assertSee('project override');
        $response->assertSee('Failed');
        $response->assertSee($lastFinishedAt->diffForHumans());
        $response->assertSeeInOrder(['Sources', '2', 'Destinations', '1', 'Broadcasts', '1']);
        $response->assertSee(route('project-sources.index', [$organization, $project]), false);
        $response->assertSee(route('project-destinations.index', [$organization, $project]), false);
        $response->assertSee(route('project-broadcasts.index', [$organization, $project]), false);
        $response->assertSee(route('project-runs.show', [$organization, $project, $newer]), false);
        $response->assertSee(route('project-runs.show', [$organization, $project, $older]), false);
        $response->assertSee('found 4');
        $response->assertSee('found 2');
        $response->assertDontSee('found 99');
        $response->assertSee('Run now');
        $response->assertSee('Dry run');
        $response->assertSee('Pause');
        $response->assertSee('Edit project');
        $response->assertDontSee('Nothing to chase yet');
        $this->assertLessThan(20, $queries);
    }

    public function test_a_never_run_project_shows_never_run_and_next_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $organization->forceFill(['timezone' => 'UTC'])->save();

        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ]);
        Source::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Never run')
            ->assertSee('This project has never run.')
            ->assertSee('UTC')
            ->assertSee('organization timezone')
            ->assertDontSee('Nothing to chase yet');
    }

    public function test_an_empty_project_guides_toward_the_first_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Fresh pipeline']);

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Nothing to chase yet')
            ->assertSee('Add a source')
            ->assertSee(route('project-sources.create', [$organization, $project]), false)
            ->assertSee(route('project-broadcasts.index', [$organization, $project]), false)
            ->assertSee('This project has no schedule yet.')
            ->assertSee('Never run');
    }

    public function test_a_viewer_sees_state_but_cannot_trigger_actions(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();
        Source::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Active')
            ->assertDontSee('Run now')
            ->assertDontSee('Dry run')
            ->assertDontSee('Pause')
            ->assertDontSee('Resume')
            ->assertDontSee('Edit project')
            ->assertDontSee('Add a source');

        $this->actingAs($user)
            ->post(route('due-projects.store', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('paused-projects.store', [$organization, $project]))
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
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('A broadcast was auto-paused after repeated failures.')
            ->assertSee('Fragile route')
            ->assertSee('Telegram rejected the message: chat not found')
            ->assertSee(route('broadcasts.edit', [$organization, $broadcast]), false);
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
