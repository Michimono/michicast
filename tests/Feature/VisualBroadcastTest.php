<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\ProjectKind;
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
use App\Support\ExecutingProjectRunner;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VisualBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_form_offers_both_kinds(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertOk()
            ->assertSee('Broadcast type')
            ->assertSee('Simple broadcast')
            ->assertSee('Visual broadcast');
    }

    public function test_creating_a_visual_broadcast_opens_the_pipeline_editor(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $response = $this->actingAs($user)->post(route('broadcasts.store', $organization), [
            'name' => 'Canvas news',
            'kind' => 'visual',
        ]);

        $project = Project::query()->where('name', 'Canvas news')->sole();
        $this->assertSame(ProjectKind::Visual, $project->kind);
        $this->assertNull($project->graph);
        $response->assertRedirect(route('pipelines.edit', [$organization, $project]));
    }

    public function test_creating_without_a_kind_stays_simple(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)->post(route('broadcasts.store', $organization), ['name' => 'Plain']);

        $project = Project::query()->where('name', 'Plain')->sole();
        $this->assertSame(ProjectKind::Simple, $project->kind);

        $this->actingAs($user)
            ->post(route('broadcasts.store', $organization), ['name' => 'Odd', 'kind' => 'magic'])
            ->assertSessionHasErrors('kind');
    }

    public function test_a_visual_broadcast_overview_redirects_to_the_editor_and_back(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $visual = Project::factory()->for($organization)->visual()->create();
        $simple = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $visual]))
            ->assertRedirect(route('pipelines.edit', [$organization, $visual]));

        $this->actingAs($user)
            ->get(route('pipelines.edit', [$organization, $simple]))
            ->assertRedirect(route('broadcasts.show', [$organization, $simple]));

        $this->actingAs($user)
            ->patch(route('pipelines.update', [$organization, $simple]), ['graph' => ['nodes' => [], 'edges' => []]])
            ->assertNotFound();
    }

    public function test_the_listing_distinguishes_kinds(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        Project::factory()->for($organization)->visual()->create(['name' => 'Drawn']);
        Project::factory()->for($organization)->create(['name' => 'Plain']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('title="Visual broadcast"', false)
            ->assertSee('title="Simple broadcast"', false)
            ->assertSee('>Visual</span>', false)
            ->assertSee('>Simple</span>', false);

        $this->actingAs($user)
            ->get(route('broadcasts.index', $organization))
            ->assertOk()
            ->assertSee('Visual')
            ->assertSee('Simple');
    }

    public function test_the_editor_renders_for_members_and_hides_editing_from_viewers(): void
    {
        [$editor, $organization] = $this->memberHolding(Role::Editor);
        $viewer = User::factory()->create();
        $organization->members()->create(['user_id' => $viewer->getKey(), 'role' => Role::Viewer]);
        $project = Project::factory()->for($organization)->visual()->create(['name' => 'Canvas news']);

        $this->actingAs($editor)
            ->get(route('pipelines.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Canvas news')
            ->assertSee('data-palette-add="source"', false)
            ->assertSee('"can_edit":true', false);

        $this->actingAs($viewer)
            ->get(route('pipelines.edit', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('data-palette-add', false)
            ->assertSee('"can_edit":false', false);

        $this->actingAs($viewer)
            ->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => ['nodes' => [], 'edges' => []]])
            ->assertForbidden();
    }

    public function test_outsiders_cannot_reach_the_pipeline(): void
    {
        [, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->visual()->create();
        $stranger = User::factory()->create();
        Organization::factory()->withMember($stranger, Role::Owner)->create();

        $this->actingAs($stranger)
            ->get(route('pipelines.edit', [$organization, $project]))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => ['nodes' => [], 'edges' => []]])
            ->assertNotFound();
    }

    public function test_saving_a_graph_compiles_routes_for_every_source_to_destination_path(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [$feed, $other] = $this->twoSources($project);
        [$news, $alerts] = $this->twoDestinations($project);

        $response = $this->actingAs($user)->patchJson(
            route('pipelines.update', [$organization, $project]),
            ['graph' => $this->fanOutGraph($feed, $other, $news, $alerts)],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('issues', [])
            ->assertJsonCount(3, 'routes')
            ->assertJsonPath('project.can_activate', true);

        $project->refresh();
        $this->assertSame(1, $project->graph['version']);
        $this->assertCount(6, $project->graph['nodes']);
        $this->assertSame(['x' => 40, 'y' => 40, 'zoom' => 1], $project->graph['viewport']);

        $rows = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->get();
        $this->assertCount(3, $rows);

        $filtered = $rows->first(fn (Broadcast $row): bool => $row->source_id === $feed->getKey() && $row->destination_id === $news->getKey());
        $this->assertNotNull($filtered);
        $this->assertTrue($filtered->enabled);
        $this->assertSame([['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']], $filtered->filter['rules']);
        $this->assertNull($filtered->transform);
        $this->assertSame('News: {{title}}', $filtered->mapping['steps'][0]['config']['value']);

        $direct = $rows->first(fn (Broadcast $row): bool => $row->source_id === $other->getKey() && $row->destination_id === $alerts->getKey());
        $this->assertNotNull($direct);
        $this->assertNull($direct->filter);
        $this->assertNull($direct->transform);

        $this->assertNotNull($rows->first(fn (Broadcast $row): bool => $row->source_id === $other->getKey() && $row->destination_id === $news->getKey()));
    }

    public function test_redrawing_keeps_matching_rows_and_archives_dropped_ones(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [$feed, $other] = $this->twoSources($project);
        [$news, $alerts] = $this->twoDestinations($project);

        $this->actingAs($user)->patchJson(
            route('pipelines.update', [$organization, $project]),
            ['graph' => $this->fanOutGraph($feed, $other, $news, $alerts)],
        )->assertOk();

        $kept = Broadcast::query()->where('source_id', $feed->getKey())->where('destination_id', $news->getKey())->sole();
        $dropped = Broadcast::query()->where('source_id', $other->getKey())->where('destination_id', $alerts->getKey())->sole();

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'dst', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
            ],
            'edges' => [['id' => 'e', 'from' => 'src', 'to' => 'dst']],
        ]])->assertOk()->assertJsonCount(1, 'routes')->assertJsonPath('routes.0.broadcast_id', $kept->getKey());

        $kept->refresh();
        $dropped->refresh();
        $this->assertFalse($kept->isArchived());
        $this->assertNull($kept->filter, 'The filter node was removed, so the row no longer filters.');
        $this->assertTrue($dropped->isArchived());
        $this->assertSame(1, Broadcast::query()->where('project_id', $project->getKey())->notArchived()->count());

        // Bringing the pair back restores the archived row instead of making a new one.
        $this->actingAs($user)->patchJson(
            route('pipelines.update', [$organization, $project]),
            ['graph' => $this->fanOutGraph($feed, $other, $news, $alerts)],
        )->assertOk();

        $this->assertFalse($dropped->refresh()->isArchived());
        $this->assertSame(3, Broadcast::query()->where('project_id', $project->getKey())->count());
    }

    public function test_structural_errors_are_rejected_and_nothing_is_saved(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [$feed] = $this->twoSources($project);
        [$news] = $this->twoDestinations($project);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'a', 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'x']]]],
                ['id' => 'b', 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'y']]]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'a', 'to' => 'b'],
                ['id' => 'e2', 'from' => 'b', 'to' => 'a'],
            ],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['graph.edges']);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'dst', 'type' => 'destination', 'x' => 0, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
                ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
            ],
            'edges' => [['id' => 'e', 'from' => 'dst', 'to' => 'src']],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['graph.edges.0.nodes']);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [['id' => 'x', 'type' => 'robot', 'x' => 0, 'y' => 0, 'config' => []]],
            'edges' => [],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['graph.nodes.0.type']);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['graph']);

        $this->assertNull($project->refresh()->graph);
        $this->assertSame(0, Broadcast::query()->where('project_id', $project->getKey())->count());
    }

    public function test_two_nodes_for_the_same_source_each_deliver(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$feed] = $this->twoSources($project);
        [$news] = $this->twoDestinations($project);

        $response = $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 's1', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 's2', 'type' => 'source', 'x' => 0, 'y' => 160, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'd', 'type' => 'destination', 'x' => 320, 'y' => 80, 'config' => ['destination_id' => $news->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 's1', 'to' => 'd'],
                ['id' => 'e2', 'from' => 's2', 'to' => 'd'],
            ],
        ]]);

        $response->assertOk();
        $response->assertJsonCount(2, 'routes');
        $response->assertJsonPath('routes.0.node_ids', ['s1', 'd']);
        $response->assertJsonPath('routes.1.node_ids', ['s2', 'd']);
        $response->assertJsonPath('issues.0.level', 'warning');
        $response->assertJsonPath('issues.0.node_id', 's2');
        $this->assertNotSame($response->json('routes.0.broadcast_id'), $response->json('routes.1.broadcast_id'));

        $this->assertSame(2, Broadcast::query()->where('project_id', $project->getKey())->count());

        Broadcast::query()->where('project_id', $project->getKey())->update([
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $this->recordingDestination();
        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $this->assertSame(
            4,
            ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count(),
            'Each source node is its own path, so both feed items are sent once per node.',
        );
    }

    public function test_parallel_filters_from_one_source_node_send_an_item_once(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$feed] = $this->twoSources($project);
        [$news] = $this->twoDestinations($project);

        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'pokemon', 'type' => 'filter', 'x' => 300, 'y' => 0, 'config' => [
                    'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
                ]],
                ['id' => 'any', 'type' => 'filter', 'x' => 300, 'y' => 200, 'config' => [
                    'rules' => [['type' => 'contains', 'field' => 'body', 'value' => '.']],
                ]],
                ['id' => 'unrelated', 'type' => 'filter', 'x' => 300, 'y' => 400, 'config' => [
                    'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'nothing matches this']],
                ]],
                ['id' => 'd', 'type' => 'destination', 'x' => 600, 'y' => 100, 'config' => ['destination_id' => $news->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 's', 'to' => 'pokemon'],
                ['id' => 'e2', 'from' => 's', 'to' => 'any'],
                ['id' => 'e3', 'from' => 's', 'to' => 'unrelated'],
                ['id' => 'e4', 'from' => 'pokemon', 'to' => 'd'],
                ['id' => 'e5', 'from' => 'any', 'to' => 'd'],
                ['id' => 'e6', 'from' => 'unrelated', 'to' => 'd'],
            ],
        ];

        $response = $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => $graph]);

        $response->assertOk()->assertJsonCount(3, 'routes');
        $this->assertSame([], array_values(array_filter(
            $response->json('issues'),
            static fn (array $issue): bool => $issue['level'] === 'error',
        )));
        $this->assertSame(3, Broadcast::query()->where('project_id', $project->getKey())->notArchived()->count());

        $ids = Broadcast::query()->where('project_id', $project->getKey())->orderBy('id')->pluck('id')->all();

        // Saving the same graph again keeps every path on its own row.
        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => $graph])->assertOk();
        $this->assertSame($ids, Broadcast::query()->where('project_id', $project->getKey())->notArchived()->orderBy('id')->pluck('id')->all());

        Broadcast::query()->where('project_id', $project->getKey())->update(['created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC')]);

        $this->recordingDestination();
        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $delivered = ContentDelivery::query()
            ->where('status', DeliveryStatus::Delivered)
            ->with('contentItem')
            ->get()
            ->map(static fn (ContentDelivery $delivery): string => (string) $delivery->contentItem?->external_id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['keep-1', 'skip-1'],
            $delivered,
            'An item passing two parallel paths from one source node is sent once; the third path matches nothing.',
        );

        $keep = ContentDelivery::query()
            ->where('status', DeliveryStatus::Delivered)
            ->whereHas('contentItem', static fn ($query) => $query->where('external_id', 'keep-1'))
            ->with('broadcast')
            ->sole();
        $this->assertSame(
            'pokemon',
            $keep->broadcast?->filter['rules'][0]['value'] ?? null,
            'The highest matching path on the canvas is the one that sends it.',
        );

        // A second run sends nothing new through either path.
        $this->travelTo(Carbon::parse('2026-03-15 11:00:00', 'UTC'));
        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));
        $this->assertSame(2, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_a_legacy_row_is_claimed_by_its_path_and_keeps_its_history(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [$feed] = $this->twoSources($project);
        [$news] = $this->twoDestinations($project);

        $legacy = $project->broadcasts()->create([
            'name' => 'Main feed → News channel',
            'source_id' => $feed->getKey(),
            'destination_id' => $news->getKey(),
        ]);
        $this->assertNull($legacy->route_key);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 's', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'f', 'type' => 'filter', 'x' => 300, 'y' => 0, 'config' => [
                    'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
                ]],
                ['id' => 'd', 'type' => 'destination', 'x' => 600, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 's', 'to' => 'd'],
                ['id' => 'e2', 'from' => 's', 'to' => 'f'],
                ['id' => 'e3', 'from' => 'f', 'to' => 'd'],
            ],
        ]])->assertOk()->assertJsonCount(2, 'routes');

        $rows = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn (Broadcast $row): bool => $row->is($legacy)));
        $this->assertNotNull($legacy->refresh()->route_key);
        $this->assertCount(2, $rows->pluck('route_key')->unique());
    }

    public function test_each_mapping_node_sends_its_own_fields(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        $feed = Source::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'Main feed',
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'pokemon-1',
                    'title' => 'Pokemon',
                    'body' => 'A card',
                    'canonical_url' => 'https://example.com/posts/pokemon-1',
                    'published_at' => '2026-03-15 09:00:00',
                ]],
            ],
        ]);
        [$news, $alerts] = $this->twoDestinations($project);

        $response = $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'feed', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'news-map', 'type' => 'mapping', 'x' => 200, 'y' => 0, 'config' => [
                    'mapping' => [
                        'version' => 1,
                        'source_provider' => 'fake',
                        'destination_provider' => 'recording',
                        'steps' => [[
                            'id' => 'news-text',
                            'operation' => 'set_field',
                            'order' => 0,
                            'config' => ['field' => 'text', 'value' => 'News: {{title}}'],
                        ]],
                    ],
                ]],
                ['id' => 'news', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
                ['id' => 'tpl', 'type' => 'transform', 'x' => 200, 'y' => 160, 'config' => ['template' => 'Alert: {{title}}']],
                ['id' => 'alerts', 'type' => 'destination', 'x' => 400, 'y' => 160, 'config' => ['destination_id' => $alerts->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'feed', 'to' => 'news-map'],
                ['id' => 'e2', 'from' => 'news-map', 'to' => 'news'],
                ['id' => 'e3', 'from' => 'feed', 'to' => 'tpl'],
                ['id' => 'e4', 'from' => 'tpl', 'to' => 'alerts'],
            ],
        ]]);

        $response->assertOk();

        $newsRow = Broadcast::query()->where('source_id', $feed->getKey())->where('destination_id', $news->getKey())->sole();
        $alertsRow = Broadcast::query()->where('source_id', $feed->getKey())->where('destination_id', $alerts->getKey())->sole();
        $this->assertSame('News: {{title}}', $newsRow->mapping['steps'][0]['config']['value']);
        $this->assertNull($alertsRow->transform);
        $this->assertSame('Alert: {{title}}', $alertsRow->mapping['steps'][0]['config']['value']);

        Broadcast::query()->where('project_id', $project->getKey())->update([
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $this->recordingDestination();
        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $bodies = array_map(
            static fn (PreparedMessage $message): string => $message->body,
            $this->recordingDestination()->published(),
        );
        sort($bodies);
        $this->assertSame(['Alert: Pokemon', 'News: Pokemon'], $bodies);
    }

    public function test_a_transform_changes_a_field_before_or_after_the_mapping(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        $feed = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'pokemon-1',
                    'title' => '<b>Pokemon</b>',
                    'canonical_url' => 'https://example.com/posts/pokemon-1',
                    'published_at' => '2026-03-15 09:00:00',
                ]],
            ],
        ]);
        [$news, $alerts] = $this->twoDestinations($project);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'feed', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'clean', 'type' => 'transform', 'x' => 160, 'y' => 0, 'config' => [
                    'rules' => [['type' => 'strip_html', 'field' => 'title']],
                ]],
                ['id' => 'news-map', 'type' => 'mapping', 'x' => 320, 'y' => 0, 'config' => [
                    'mapping' => [
                        'version' => 1,
                        'steps' => [[
                            'id' => 'news-text',
                            'operation' => 'set_field',
                            'order' => 0,
                            'config' => ['field' => 'text', 'value' => '{{title}}'],
                        ]],
                    ],
                ]],
                ['id' => 'news', 'type' => 'destination', 'x' => 480, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
                ['id' => 'alerts-map', 'type' => 'mapping', 'x' => 160, 'y' => 160, 'config' => [
                    'mapping' => [
                        'version' => 1,
                        'steps' => [[
                            'id' => 'alerts-text',
                            'operation' => 'set_field',
                            'order' => 0,
                            'config' => ['field' => 'text', 'value' => 'Hello <b>there</b>'],
                        ]],
                    ],
                ]],
                ['id' => 'strip', 'type' => 'transform', 'x' => 320, 'y' => 160, 'config' => [
                    'rules' => [['type' => 'strip_html', 'field' => 'text']],
                ]],
                ['id' => 'alerts', 'type' => 'destination', 'x' => 480, 'y' => 160, 'config' => ['destination_id' => $alerts->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'feed', 'to' => 'clean'],
                ['id' => 'e2', 'from' => 'clean', 'to' => 'news-map'],
                ['id' => 'e3', 'from' => 'news-map', 'to' => 'news'],
                ['id' => 'e4', 'from' => 'feed', 'to' => 'alerts-map'],
                ['id' => 'e5', 'from' => 'alerts-map', 'to' => 'strip'],
                ['id' => 'e6', 'from' => 'strip', 'to' => 'alerts'],
            ],
        ]])->assertOk();

        Broadcast::query()->where('project_id', $project->getKey())->update([
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $this->recordingDestination();
        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $bodies = array_map(
            static fn (PreparedMessage $message): string => $message->body,
            $this->recordingDestination()->published(),
        );
        sort($bodies);
        $this->assertSame(['Hello there', 'Pokemon'], $bodies);
    }

    public function test_semantic_problems_save_with_issues_but_compile_no_route_through_them(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        [$feed] = $this->twoSources($project);
        [$news] = $this->twoDestinations($project);
        $archived = Destination::factory()->archived()->create(['project_id' => $project->getKey()]);
        $foreign = Source::factory()->create();

        $response = $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => [
            'nodes' => [
                ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'foreign', 'type' => 'source', 'x' => 0, 'y' => 200, 'config' => ['source_id' => $foreign->getKey()]],
                ['id' => 'empty', 'type' => 'filter', 'x' => 200, 'y' => 0, 'config' => ['rules' => []]],
                ['id' => 'dst', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
                ['id' => 'old', 'type' => 'destination', 'x' => 400, 'y' => 200, 'config' => ['destination_id' => $archived->getKey()]],
                ['id' => 'none', 'type' => 'destination', 'x' => 400, 'y' => 400, 'config' => ['destination_id' => null]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'src', 'to' => 'empty'],
                ['id' => 'e2', 'from' => 'empty', 'to' => 'dst'],
                ['id' => 'e3', 'from' => 'src', 'to' => 'old'],
                ['id' => 'e4', 'from' => 'foreign', 'to' => 'dst'],
                ['id' => 'e5', 'from' => 'src', 'to' => 'none'],
            ],
        ]]);

        $response->assertOk()->assertJsonPath('routes', []);

        $issues = collect($response->json('issues'));
        $this->assertSame('error', $issues->firstWhere('node_id', 'empty')['level']);
        $this->assertSame('error', $issues->firstWhere('node_id', 'old')['level']);
        $this->assertSame('error', $issues->firstWhere('node_id', 'none')['level']);
        $this->assertSame('Choose a source for this node.', $issues->firstWhere('node_id', 'foreign')['message']);

        $this->assertNotNull($project->refresh()->graph);
        $this->assertSame(0, Broadcast::query()->where('project_id', $project->getKey())->notArchived()->count());
        $this->assertFalse($project->canActivate());
        $this->assertSame('Connect a source to a destination on the canvas to play this broadcast.', $project->activationBlockReason());
    }

    public function test_a_visual_broadcast_accepts_several_sources_and_destinations(): void
    {
        [$user, $organization, $project] = $this->visualProject();
        Source::factory()->create(['project_id' => $project->getKey()]);
        Destination::factory()->create(['project_id' => $project->getKey()]);

        $this->actingAs($user)
            ->get(route('broadcast-sources.create', [$organization, $project]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk();

        $this->actingAs($user)->post(route('broadcast-sources.store', [$organization, $project]), [
            'name' => 'Second feed',
            'type' => 'fake',
            'config' => ['feed_url' => 'https://example.com/second.xml'],
        ])->assertRedirect(route('pipelines.edit', [$organization, $project]));

        $this->assertSame(2, $project->sources()->count());
        $this->assertSame(0, $project->broadcasts()->count(), 'Visual broadcasts never get an implicit default route.');
    }

    public function test_the_runner_delivers_through_compiled_routes(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$feed, $other] = $this->twoSources($project);
        [$news, $alerts] = $this->twoDestinations($project);

        $this->actingAs($user)->patchJson(
            route('pipelines.update', [$organization, $project]),
            ['graph' => $this->fanOutGraph($feed, $other, $news, $alerts)],
        )->assertOk();

        // Routes are born now; the items were published earlier, so push the cut-off back.
        Broadcast::query()->where('project_id', $project->getKey())->update(['created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC')]);

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertFalse($run->is_dry);
        $this->assertSame(3, $run->items_delivered);
        $published = array_slice($recording->published(), $before);
        $bodies = array_map(static fn ($message): string => trim($message->body), $published);
        sort($bodies);

        $this->assertSame([
            'News: Pokemon drop',
            'Other feed item',
            'Other feed item',
        ], $bodies);

        $this->assertSame(3, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
        $this->assertSame(
            1,
            ContentDelivery::query()->where('status', DeliveryStatus::Delivered)
                ->whereHas('broadcast', fn ($query) => $query->where('destination_id', $news->getKey())->where('source_id', $feed->getKey()))
                ->count(),
            'The filter keeps the unrelated item from the news channel.',
        );
    }

    public function test_the_test_button_dry_runs_without_sending(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->visualProject(active: true);
        [$feed, $other] = $this->twoSources($project);
        [$news, $alerts] = $this->twoDestinations($project);

        $this->actingAs($user)->patchJson(
            route('pipelines.update', [$organization, $project]),
            ['graph' => $this->fanOutGraph($feed, $other, $news, $alerts)],
        )->assertOk();
        Broadcast::query()->where('project_id', $project->getKey())->update(['created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC')]);

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $response = $this->actingAs($user)->postJson(route('broadcast-dry-runs.store', [$organization, $project]));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('run.would_publish', 3)
            ->assertJsonPath('run.items_found', 4)
            ->assertJsonCount(3, 'run.routes');

        $route = collect($response->json('run.routes'))->firstWhere('source_id', $feed->getKey());
        $this->assertSame($news->getKey(), $route['destination_id']);
        $this->assertTrue(collect($route['report'])->contains(
            fn (array $entry): bool => $entry['outcome'] === 'publish' && $entry['rendered'] === 'News: Pokemon drop',
        ));

        $this->assertSame($before, count($recording->published()));
        $this->assertSame(0, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
        $this->assertTrue(ProjectRun::query()->where('project_id', $project->getKey())->sole()->is_dry);
    }

    public function test_a_simple_broadcast_is_untouched(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk();

        Source::factory()->create(['project_id' => $project->getKey()]);
        Destination::factory()->create(['project_id' => $project->getKey()]);
        $project->ensureDefaultBroadcast();

        $this->assertSame(1, $project->broadcasts()->count());
        $this->assertSame(ProjectKind::Simple, $project->refresh()->kind);
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function visualProject(bool $active = false): array
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $factory = Project::factory()->for($organization)->visual();

        if ($active) {
            $factory = $factory->active();
        }

        return [$user, $organization, $factory->create(['name' => 'Canvas news'])];
    }

    /**
     * @return array{Source, Source}
     */
    private function twoSources(Project $project): array
    {
        $feed = Source::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'Main feed',
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    [
                        'external_id' => 'keep-1',
                        'title' => 'Pokemon drop',
                        'body' => 'A keepable item.',
                        'canonical_url' => 'https://example.com/posts/keep-1',
                        'published_at' => '2026-03-15 09:00:00',
                    ],
                    [
                        'external_id' => 'skip-1',
                        'title' => 'Unrelated news',
                        'body' => 'Filtered out.',
                        'canonical_url' => 'https://example.com/posts/skip-1',
                        'published_at' => '2026-03-14 09:00:00',
                    ],
                ],
            ],
        ]);

        $other = Source::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'Other feed',
            'config' => [
                'feed_url' => 'https://example.com/other.xml',
                'items' => [[
                    'external_id' => 'other-1',
                    'title' => 'Other feed item',
                    'canonical_url' => 'https://example.com/other/1',
                    'published_at' => '2026-03-15 08:00:00',
                ]],
            ],
        ]);

        return [$feed, $other];
    }

    /**
     * @return array{Destination, Destination}
     */
    private function twoDestinations(Project $project): array
    {
        return [
            Destination::factory()->create(['project_id' => $project->getKey(), 'name' => 'News channel']),
            Destination::factory()->create(['project_id' => $project->getKey(), 'name' => 'Alerts channel']),
        ];
    }

    /**
     * Main feed → filter → transform → News; Other feed → transform → News;
     * Other feed → Alerts.
     *
     * @return array<string, mixed>
     */
    private function fanOutGraph(Source $feed, Source $other, Destination $news, Destination $alerts): array
    {
        return [
            'nodes' => [
                ['id' => 'feed', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $feed->getKey()]],
                ['id' => 'other', 'type' => 'source', 'x' => 0, 'y' => 200, 'config' => ['source_id' => $other->getKey()]],
                ['id' => 'flt', 'type' => 'filter', 'x' => 300, 'y' => 0, 'config' => [
                    'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
                ]],
                ['id' => 'tpl', 'type' => 'transform', 'x' => 600, 'y' => 0, 'config' => ['template' => 'News: {{title}}']],
                ['id' => 'news', 'type' => 'destination', 'x' => 900, 'y' => 0, 'config' => ['destination_id' => $news->getKey()]],
                ['id' => 'alerts', 'type' => 'destination', 'x' => 900, 'y' => 200, 'config' => ['destination_id' => $alerts->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'feed', 'to' => 'flt'],
                ['id' => 'e2', 'from' => 'flt', 'to' => 'tpl'],
                ['id' => 'e3', 'from' => 'tpl', 'to' => 'news'],
                ['id' => 'e4', 'from' => 'other', 'to' => 'news'],
                ['id' => 'e5', 'from' => 'other', 'to' => 'alerts'],
            ],
            'viewport' => ['x' => 40, 'y' => 40, 'zoom' => 1],
        ];
    }

    private function recordingDestination(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
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
