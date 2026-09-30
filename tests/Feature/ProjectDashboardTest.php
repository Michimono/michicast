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

        $lastFinishedAt = now()->subHour();
        ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subHours(5),
            'finished_at' => now()->subHours(5)->addMinute(),
            'items_found' => 2,
            'items_delivered' => 1,
        ]);
        ProjectRun::factory()->for($project)->failed()->create([
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
        $response->assertSee('RSS Feed');
        $response->assertSee('Telegram');
        $response->assertDontSee('Archived feed');
        $response->assertDontSee('Archived channel');
        $response->assertDontSee('Recent activity');
        $response->assertDontSee('>Archive</button>', false);
        $response->assertSee('Daily at 9:00');
        $response->assertSee('Settings');
        $response->assertSee('Run now');
        $response->assertSee('Pause');
        $response->assertDontSee('Play');
        $response->assertSeeInOrder(['Overview', 'Active', 'Attention needed'], false);
        $response->assertDontSee('Dry run');
        $response->assertDontSee('All activity');
        $response->assertSee(route('broadcasts.edit', [$organization, $project]), false);
        // Activity lives in the sidebar, not the header.
        $response->assertSee(route('sources.edit', [$organization, $source]), false);
        $response->assertSee(route('destinations.edit', [$organization, $destination]), false);
        $response->assertDontSee('Save details');
        $response->assertDontSee('name="name"', false);
        $response->assertDontSee(route('broadcast-pipelines.index', [$organization, $project]), false);
        $response->assertDontSee('No source yet');
        $response->assertDontSee('No mapping yet');
        $response->assertDontSee('No destination yet');
        $response->assertDontSee('Add a source');
        $response->assertDontSee('Add destination');
        $response->assertSeeInOrder(['Source', 'RSS Feed', 'Manage', 'Mapping', 'Destination', 'Telegram', 'Manage'], false);
        $response->assertSee('cursor-pointer', false);
        $response->assertDontSee(route('broadcast-sources.create', [$organization, $project]), false);
        $response->assertDontSee(route('broadcast-destinations.create', [$organization, $project]), false);
        $this->assertLessThan(40, $queries);

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Archive')
            ->assertSee(route('archived-broadcasts.store', [$organization, $project]), false);
    }

    public function test_a_never_run_project_lists_health_without_recent_activity_card(): void
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
            ->assertDontSee('Recent activity')
            ->assertDontSee('No source yet');
    }

    public function test_an_empty_project_guides_toward_the_pipeline(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Fresh pipeline']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('No source yet')
            ->assertSee('No mapping yet')
            ->assertSee('No destination yet')
            ->assertSee('Nothing feeding this broadcast yet.')
            ->assertSee('Nowhere to publish yet.')
            ->assertSee('Connect the source fields to the destination fields.')
            ->assertSeeInOrder(['Source', 'No source yet', 'Add a source', 'Mapping', 'No mapping yet', 'Destination', 'No destination yet', 'Add destination'], false)
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertSee('Never run')
            ->assertSee('Draft')
            ->assertSee('No schedule set')
            ->assertSee('Settings')
            ->assertSee('Play')
            ->assertDontSee('Run now')
            ->assertSee(route('broadcasts.edit', [$organization, $project]), false)
            ->assertDontSee('Recent activity')
            ->assertDontSee('Dry run')
            ->assertDontSee('All activity')
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
            ->assertDontSee('Add a source')
            ->assertSeeInOrder(['Source', 'Only feed', 'Manage', 'Mapping', 'Destination', 'Add destination'], false)
            ->assertDontSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertDontSee('Nothing feeding this broadcast yet.');

        Destination::factory()->for($project)->create(['name' => 'Only channel']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('Add a source')
            ->assertDontSee('Add destination')
            ->assertSeeInOrder(['Source', 'Only feed', 'Manage', 'Mapping', 'Destination', 'Only channel', 'Manage'], false)
            ->assertDontSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertDontSee(route('broadcast-destinations.create', [$organization, $project]), false)
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
            ->assertSee('No source yet')
            ->assertSee('No mapping yet')
            ->assertSee('No destination yet')
            ->assertSee('Nothing feeding this broadcast yet.')
            ->assertSee('Nowhere to publish yet.')
            ->assertSeeInOrder(['Source', 'No source yet', 'Add a source', 'Destination', 'No destination yet', 'Add destination'], false)
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
            ->assertDontSee('Play')
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

    public function test_overview_puts_status_tags_on_the_kicker_and_play_with_settings(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Ready draft']);
        Broadcast::factory()->for($project)->create();

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Draft', 'Never run', 'Play', 'Settings'], false)
            ->assertSee('Play')
            ->assertSee(route('active-broadcasts.store', [$organization, $project]), false)
            ->assertDontSee('Run now')
            ->assertDontSee('A project cannot be activated until it has at least one enabled broadcast.', false)
            ->getContent();

        // Tags stay alone on the Overview kicker line; Play shares one button row with Settings.
        $this->assertMatchesRegularExpression(
            '/<div\b[^>]*font-mono[^>]*>\s*Overview[\s\S]*house-fill[\s\S]*Draft[\s\S]*Never run/i',
            $html,
        );

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $playInKicker = $xpath->query('//div[contains(@class, "font-mono")][contains(., "Overview")]//form[.//button[contains(normalize-space(.), "Play")]]');
        $this->assertSame(0, $playInKicker->length, 'Play must not sit on the Overview kicker row with the status tags');

        $buttonRow = $xpath->query('//div[contains(@class, "flex-wrap") and contains(@class, "justify-end") and contains(@class, "gap-2")][.//form[.//button[contains(normalize-space(.), "Play")]] and .//*[contains(normalize-space(.), "Settings")]]');
        $this->assertGreaterThan(0, $buttonRow->length, 'Play and Settings must share one horizontal button row');

        $stackedColumn = $xpath->query('//div[contains(@class, "flex-col") and contains(@class, "items-end")][.//form[.//button[contains(normalize-space(.), "Play")]]]');
        $this->assertSame(0, $stackedColumn->length, 'Play must not stack alone above Settings in a flex-col column');
    }

    public function test_overview_keeps_pause_settings_and_run_now_on_one_row(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create(['name' => 'Live show']);
        Broadcast::factory()->for($project)->create();

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSeeInOrder(['Overview', 'Active', 'Pause', 'Settings', 'Run now'], false)
            ->assertDontSee('Play')
            ->getContent();

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $buttonRow = $xpath->query('//div[contains(@class, "flex-wrap") and contains(@class, "justify-end") and contains(@class, "gap-2")][.//form[.//button[contains(normalize-space(.), "Pause")]] and .//*[contains(normalize-space(.), "Settings")] and .//form[.//button[contains(normalize-space(.), "Run now")]]]');
        $this->assertGreaterThan(0, $buttonRow->length, 'Pause, Settings, and Run now must share one horizontal button row');

        $stackedColumn = $xpath->query('//div[contains(@class, "flex-col") and contains(@class, "items-end")][.//form[.//button[contains(normalize-space(.), "Pause")]]]');
        $this->assertSame(0, $stackedColumn->length, 'Pause must not stack alone above Settings in a flex-col column');
    }

    public function test_overview_disables_play_with_a_reason_when_pipeline_is_missing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Play')
            ->assertSee('disabled', false)
            ->assertSee('A project cannot be activated until it has at least one enabled broadcast.', false)
            ->assertDontSee(route('active-broadcasts.store', [$organization, $project]), false);
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
