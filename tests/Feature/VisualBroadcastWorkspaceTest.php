<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VisualBroadcastWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_editor_stays_on_the_canvas_and_does_not_embed_credentials(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        $this->wiredSourceAndDestination($project);

        $this->actingAs($user)
            ->get(route('pipelines.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('data-open-panel="activity"', false)
            ->assertSee('data-open-panel="settings"', false)
            ->assertSee('data-open-panel="issues"', false)
            ->assertSee('data-pipeline-test', false)
            ->assertSee('data-palette-ghost', false)
            ->assertSee('data-draft-edge', false)
            ->assertSee('"source_store"', false)
            ->assertSee('"summary_url"', false)
            ->assertDontSee('factory-secret-token');
    }

    public function test_the_editor_can_add_and_edit_a_source_without_leaving(): void
    {
        [$user, $organization, $project] = $this->visualProject();

        $this->actingAs($user)
            ->postJson(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Inspector feed',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/inspector.xml'],
            ])
            ->assertCreated()
            ->assertJsonPath('source.name', 'Inspector feed')
            ->assertJsonPath('source.type', 'fake')
            ->assertJsonPath('source.config.feed_url', 'https://example.com/inspector.xml');

        $source = $project->sources()->sole();

        $this->actingAs($user)
            ->patchJson(route('sources.update', [$organization, $source]), [
                'name' => 'Renamed feed',
                'config' => ['feed_url' => 'https://example.com/renamed.xml'],
            ])
            ->assertOk()
            ->assertJsonPath('source.name', 'Renamed feed')
            ->assertJsonPath('source.config.feed_url', 'https://example.com/renamed.xml');

        $this->actingAs($user)
            ->postJson(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Broken',
                'type' => 'fake',
                'config' => ['feed_url' => 'not-a-url'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.feed_url');
    }

    public function test_the_editor_can_add_a_destination_without_echoing_its_secret(): void
    {
        [$user, $organization, $project] = $this->visualProject();

        $this->actingAs($user)
            ->postJson(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Log',
                'type' => 'recording',
                'config' => ['channel' => 'news'],
                'credentials' => ['token' => 'inspector-secret-token'],
            ])
            ->assertCreated()
            ->assertJsonPath('destination.name', 'Log')
            ->assertJsonPath('destination.type', 'recording')
            ->assertJsonMissingPath('destination.credentials')
            ->assertDontSee('inspector-secret-token');

        $destination = $project->destinations()->sole();
        $this->assertSame('inspector-secret-token', $destination->credentials['token']);
    }

    public function test_a_source_summary_returns_observed_fields_and_not_another_orgs_source(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$source] = $this->wiredSourceAndDestination($project);
        $this->connectDirectRoute($user, $organization, $project, $source, $project->destinations()->sole());

        $this->actingAs($user)
            ->postJson(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('source-summaries.show', [$organization, $source]))
            ->assertOk()
            ->assertJsonPath('source.id', $source->getKey())
            ->assertJsonPath('sample.fields.title', 'Pokemon drop')
            ->assertJsonPath('fields.0.name', 'title');

        $stranger = User::factory()->create();
        Organization::factory()->withMember($stranger, Role::Owner)->create();

        $this->actingAs($stranger)
            ->getJson(route('source-summaries.show', [$organization, $source]))
            ->assertNotFound();
    }

    public function test_a_destination_summary_documents_adapter_fields_and_hides_secrets(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [, $destination] = $this->wiredSourceAndDestination($project);

        $this->actingAs($user)
            ->getJson(route('destination-summaries.show', [$organization, $destination]))
            ->assertOk()
            ->assertJsonPath('destination.id', $destination->getKey())
            ->assertJsonPath('fields.0.name', 'text')
            ->assertJsonPath('fields.0.required', true)
            ->assertJsonMissingPath('destination.credentials')
            ->assertDontSee('factory-secret-token');
    }

    public function test_a_dry_run_records_edge_traces_without_delivering(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$source, $destination] = $this->wiredSourceAndDestination($project);
        $this->connectDirectRoute($user, $organization, $project, $source, $destination);

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $response = $this->actingAs($user)->postJson(route('broadcast-dry-runs.store', [$organization, $project]));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('run.would_publish', 1)
            ->assertJsonCount(1, 'traces')
            ->assertJsonPath('traces.0.edge_id', 'e1')
            ->assertJsonPath('traces.0.status', 'flowed');

        $this->assertSame($before, count($recording->published()));
        $this->assertSame(0, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
        $this->assertTrue(ProjectRun::query()->where('project_id', $project->getKey())->sole()->is_dry);

        $this->actingAs($user)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e1']))
            ->assertOk()
            ->assertJsonPath('edge_id', 'e1')
            ->assertJsonPath('trace.status', 'flowed')
            ->assertJsonPath('trace.items.0.title', 'Pokemon drop')
            ->assertJsonPath('trace.items.0.payload.title', 'Pokemon drop');

        $stranger = User::factory()->create();
        Organization::factory()->withMember($stranger, Role::Owner)->create();

        $this->actingAs($stranger)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e1']))
            ->assertNotFound();
    }

    public function test_settings_and_activity_are_available_as_json_to_the_editor(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$source, $destination] = $this->wiredSourceAndDestination($project);
        $this->connectDirectRoute($user, $organization, $project, $source, $destination);

        $this->actingAs($user)
            ->patchJson(route('broadcasts.update', [$organization, $project]), [
                'name' => 'Renamed canvas',
                'description' => 'Edited from the inspector.',
                'frequency' => 'daily',
                'time' => '08:30',
            ])
            ->assertOk()
            ->assertJsonPath('project.name', 'Renamed canvas')
            ->assertJsonPath('project.description', 'Edited from the inspector.');

        $this->assertSame('Renamed canvas', $project->refresh()->name);
        $this->assertSame('08:30', $project->schedule['time']);

        $this->actingAs($user)
            ->postJson(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertOk();

        $this->actingAs($user)
            ->getJson(route('broadcast-runs.index', [$organization, $project]))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('runs.0.is_dry', true);
    }

    public function test_run_now_from_the_editor_stays_on_the_canvas(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$source, $destination] = $this->wiredSourceAndDestination($project);
        $this->connectDirectRoute($user, $organization, $project, $source, $destination);
        $this->recordingDestination();

        $this->actingAs($user)
            ->postJson(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('run.status', 'completed');

        $this->assertFalse(ProjectRun::query()->where('project_id', $project->getKey())->sole()->is_dry);
    }

    public function test_a_viewer_can_read_the_workspace_but_cannot_change_it(): void
    {
        [$editor, $organization, $project] = $this->visualProject();
        [$source, $destination] = $this->wiredSourceAndDestination($project);
        $this->connectDirectRoute($editor, $organization, $project, $source, $destination);

        $viewer = User::factory()->create();
        $organization->members()->create(['user_id' => $viewer->getKey(), 'role' => Role::Viewer]);

        $this->actingAs($viewer)
            ->getJson(route('source-summaries.show', [$organization, $source]))
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson(route('destination-summaries.show', [$organization, $destination]))
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e1']))
            ->assertOk()
            ->assertJsonPath('trace', null);

        $this->actingAs($viewer)
            ->postJson(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Should not land',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/no.xml'],
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patchJson(route('broadcasts.update', [$organization, $project]), [
                'name' => 'Hijacked',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function visualProject(bool $active = false): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $factory = Project::factory()->for($organization)->visual();

        if ($active) {
            $factory = $factory->active();
        }

        return [$user, $organization, $factory->create(['name' => 'Canvas news'])];
    }

    /**
     * @return array{Source, Destination}
     */
    private function wiredSourceAndDestination(Project $project): array
    {
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'Main feed',
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'keep-1',
                    'title' => 'Pokemon drop',
                    'body' => 'A keepable item.',
                    'canonical_url' => 'https://example.com/posts/keep-1',
                    'published_at' => '2026-03-15 09:00:00',
                ]],
            ],
        ]);

        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'News channel',
        ]);

        return [$source, $destination];
    }

    private function connectDirectRoute(User $user, Organization $organization, Project $project, Source $source, Destination $destination): void
    {
        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), [
            'graph' => [
                'nodes' => [
                    ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $source->getKey()]],
                    ['id' => 'dst', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $destination->getKey()]],
                ],
                'edges' => [['id' => 'e1', 'from' => 'src', 'to' => 'dst']],
            ],
        ])->assertOk();

        Broadcast::query()->where('project_id', $project->getKey())->update([
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);
    }

    private function recordingDestination(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
