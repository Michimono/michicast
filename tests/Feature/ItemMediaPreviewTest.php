<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\Mapping\DefaultMappings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ItemMediaPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA = [
        ['type' => 'image', 'url' => 'https://cdn.example.com/one.jpg'],
        ['type' => 'image', 'url' => 'https://cdn.example.com/two.jpg'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_mapping_preview_lists_what_the_destination_attaches(): void
    {
        [$user, $organization, , $broadcast] = $this->broadcastWorkspace('recording');

        $this->actingAs($user)
            ->postJson(route('broadcast-mapping-previews.store', [$organization, $broadcast]), [
                'mapping' => (new DefaultMappings)->for('fake', 'recording')?->toArray(),
                'source_data' => [
                    'title' => 'Launch day',
                    'body' => 'Doors open at nine.',
                    'link' => 'https://example.com/launch',
                    'media' => [...self::MEDIA, ['type' => 'image', 'url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('human.media.0.url', 'https://cdn.example.com/one.jpg')
            ->assertJsonPath('human.media.1.url', 'https://cdn.example.com/two.jpg')
            ->assertJsonCount(2, 'human.media')
            ->assertJsonPath('human.media_left_off', 1)
            ->assertJsonPath('human.media_note', '1 left off. This destination takes up to 10 images or videos per message.');
    }

    public function test_the_mapping_preview_says_when_a_destination_sends_text_only(): void
    {
        [$user, $organization, , $broadcast] = $this->broadcastWorkspace('telegram');

        $this->actingAs($user)
            ->postJson(route('broadcast-mapping-previews.store', [$organization, $broadcast]), [
                'mapping' => (new DefaultMappings)->for('fake', 'telegram')?->toArray(),
                'source_data' => [
                    'title' => 'Launch day',
                    'body' => 'Doors open at nine.',
                    'link' => 'https://example.com/launch',
                    'media' => self::MEDIA,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('human.text', "Launch day\n\nDoors open at nine.\n\nRead more: https://example.com/launch")
            ->assertJsonCount(0, 'human.media')
            ->assertJsonPath('human.media_left_off', 2)
            ->assertJsonPath('human.media_note', 'Not attached. This destination sends text only.')
            ->assertJsonCount(2, 'data.media');
    }

    public function test_a_preview_without_media_has_no_media_note(): void
    {
        [$user, $organization, , $broadcast] = $this->broadcastWorkspace('telegram');

        $this->actingAs($user)
            ->postJson(route('broadcast-mapping-previews.store', [$organization, $broadcast]), [
                'mapping' => (new DefaultMappings)->for('fake', 'telegram')?->toArray(),
                'source_data' => ['title' => 'Launch day', 'body' => 'Doors open at nine.', 'link' => 'https://example.com/launch', 'media' => []],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'human.media')
            ->assertJsonPath('human.media_left_off', 0)
            ->assertJsonPath('human.media_note', null);
    }

    public function test_a_destination_summary_states_what_the_destination_attaches(): void
    {
        [$user, $organization, $project] = $this->broadcastWorkspace('recording');
        $recording = $project->destinations()->sole();
        $telegram = Destination::factory()->for($project)->create([
            'type' => 'telegram',
            'config' => ['chat_id' => '@pocketnews'],
            'credentials' => ['bot_token' => '123:ABC'],
        ]);

        $this->actingAs($user)
            ->getJson(route('destination-summaries.show', [$organization, $recording]))
            ->assertOk()
            ->assertJsonPath('capabilities.supports_media', true)
            ->assertJsonPath('capabilities.media.types', ['image', 'video'])
            ->assertJsonPath('capabilities.media.max_count', 10)
            ->assertJsonPath('capabilities.media.label', 'up to 10 images or videos');

        $this->actingAs($user)
            ->getJson(route('destination-summaries.show', [$organization, $telegram]))
            ->assertOk()
            ->assertJsonPath('capabilities.supports_media', false)
            ->assertJsonPath('capabilities.media.types', [])
            ->assertJsonPath('capabilities.media.label', 'text only');
    }

    public function test_a_source_summary_shows_the_media_of_the_latest_item(): void
    {
        [$user, $organization, $project] = $this->broadcastWorkspace('recording');
        $source = $project->sources()->sole();

        ContentItem::factory()->forSource((int) $source->getKey())->create([
            'project_id' => $project->getKey(),
            'media' => self::MEDIA,
        ]);

        $this->actingAs($user)
            ->getJson(route('source-summaries.show', [$organization, $source]))
            ->assertOk()
            ->assertJsonPath('sample.fields.media_count', 2)
            ->assertJsonPath('sample.fields.media.0', ['type' => 'image', 'url' => 'https://cdn.example.com/one.jpg'])
            ->assertJsonPath('sample.fields.media.1.url', 'https://cdn.example.com/two.jpg');
    }

    public function test_a_source_summary_without_media_lists_none(): void
    {
        [$user, $organization, $project] = $this->broadcastWorkspace('recording');
        $source = $project->sources()->sole();

        ContentItem::factory()->forSource((int) $source->getKey())->create([
            'project_id' => $project->getKey(),
            'media' => null,
        ]);

        $this->actingAs($user)
            ->getJson(route('source-summaries.show', [$organization, $source]))
            ->assertOk()
            ->assertJsonPath('sample.fields.media_count', 0)
            ->assertJsonMissingPath('sample.fields.media');
    }

    public function test_a_test_run_reports_the_media_a_destination_would_leave_off(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->visual()->active()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'launch-1',
                    'title' => 'Launch day',
                    'canonical_url' => 'https://example.com/launch-1',
                    'published_at' => '2026-03-15 09:00:00',
                    'media' => self::MEDIA,
                ]],
            ],
        ]);
        $recording = Destination::factory()->create(['project_id' => $project->getKey(), 'name' => 'Log']);
        $telegram = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'name' => 'Channel',
            'type' => 'telegram',
            'config' => ['chat_id' => '@pocketnews'],
            'credentials' => ['bot_token' => '123:ABC'],
        ]);

        $this->actingAs($user)->patchJson(route('pipelines.update', [$organization, $project]), [
            'graph' => [
                'nodes' => [
                    ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $source->getKey()]],
                    ['id' => 'log-tpl', 'type' => 'transform', 'x' => 200, 'y' => 0, 'config' => ['template' => '{{title}}']],
                    ['id' => 'log', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $recording->getKey()]],
                    ['id' => 'tg-tpl', 'type' => 'transform', 'x' => 200, 'y' => 200, 'config' => ['template' => '{{title}}']],
                    ['id' => 'tg', 'type' => 'destination', 'x' => 400, 'y' => 200, 'config' => ['destination_id' => $telegram->getKey()]],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'src', 'to' => 'log-tpl'],
                    ['id' => 'e2', 'from' => 'log-tpl', 'to' => 'log'],
                    ['id' => 'e3', 'from' => 'src', 'to' => 'tg-tpl'],
                    ['id' => 'e4', 'from' => 'tg-tpl', 'to' => 'tg'],
                ],
            ],
        ])->assertOk();

        Broadcast::query()->where('project_id', $project->getKey())->update([
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->postJson(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertOk();

        // What the source emitted.
        $this->actingAs($user)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e1']))
            ->assertOk()
            ->assertJsonPath('trace.items.0.payload.media.0.type', 'image')
            ->assertJsonPath('trace.items.0.payload.media.0.url', 'https://cdn.example.com/one.jpg')
            ->assertJsonPath('trace.items.0.payload.media.1.url', 'https://cdn.example.com/two.jpg');

        // A destination that takes media would attach both.
        $this->actingAs($user)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e2']))
            ->assertOk()
            ->assertJsonPath('trace.items.0.payload.text', 'Launch day')
            ->assertJsonPath('trace.items.0.payload.media_count', 2)
            ->assertJsonMissingPath('trace.items.0.payload.media_left_off');

        // A text-only destination would leave both off and still send the text.
        $this->actingAs($user)
            ->getJson(route('pipeline-edge-traces.show', [$organization, $project, 'e4']))
            ->assertOk()
            ->assertJsonPath('trace.items.0.payload.text', 'Launch day')
            ->assertJsonPath('trace.items.0.payload.media_count', 0)
            ->assertJsonPath('trace.items.0.payload.media_left_off', 2)
            ->assertJsonPath('trace.items.0.payload.media_note', 'Not attached. This destination sends text only.');
    }

    /**
     * @return array{0: User, 1: Organization, 2: Project, 3: Broadcast}
     */
    private function broadcastWorkspace(string $destinationType): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake', 'name' => 'Pocket feed']);
        $destination = Destination::factory()->for($project)->create($destinationType === 'telegram'
            ? ['type' => 'telegram', 'name' => 'Channel', 'config' => ['chat_id' => '@pocketnews'], 'credentials' => ['bot_token' => '123:ABC']]
            : ['type' => 'recording', 'name' => 'Recorder']);
        $broadcast = $project->ensureDefaultBroadcast();
        $this->assertNotNull($broadcast);
        $broadcast->update([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        return [$user, $organization, $project, $broadcast->fresh(['source', 'destination'])];
    }
}
