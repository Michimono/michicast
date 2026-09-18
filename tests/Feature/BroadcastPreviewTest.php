<?php

namespace Tests\Feature;

use App\Enums\ContentItemLifecycle;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_fetches_from_the_source_and_never_publishes(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $broadcast] = $this->broadcastReadyForPreview();

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $this->actingAs($user)
            ->post(route('content-previews.store', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Would publish')
            ->assertSee('Pokemon drop')
            ->assertSee('News: Pokemon drop')
            ->assertSee('Unrelated news')
            ->assertSee('Would skip');

        $this->assertSame($before, count($recording->published()));
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, ContentDelivery::query()->count());
    }

    public function test_preview_does_not_write_content_items_deliveries_or_lifecycle(): void
    {
        [$user, $organization, $broadcast] = $this->broadcastReadyForPreview();

        $this->actingAs($user)
            ->post(route('content-previews.store', [$organization, $broadcast]))
            ->assertOk();

        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, ContentDelivery::query()->count());
        $this->assertSame(0, ContentItem::query()->where('lifecycle', ContentItemLifecycle::Delivered)->count());
    }

    public function test_a_viewer_cannot_preview(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create(['project_id' => $project->getKey()]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->post(route('content-previews.store', [$organization, $broadcast]))
            ->assertForbidden();
    }

    public function test_an_editor_sees_the_preview_form_on_the_broadcast(): void
    {
        [$user, $organization, $broadcast] = $this->broadcastReadyForPreview();

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee(route('content-previews.store', [$organization, $broadcast]), false);
    }

    public function test_preview_is_rate_limited(): void
    {
        [$user, $organization, $broadcast] = $this->broadcastReadyForPreview();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post(route('content-previews.store', [$organization, $broadcast]))
                ->assertOk();
        }

        $this->actingAs($user)
            ->post(route('content-previews.store', [$organization, $broadcast]))
            ->assertTooManyRequests();
    }

    public function test_runner_preview_shows_skip_reasons_without_side_effects(): void
    {
        $project = Project::factory()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    [
                        'external_id' => 'keep-1',
                        'title' => 'Pokemon drop',
                        'body' => 'A keepable item.',
                        'canonical_url' => 'https://example.com/posts/keep-1',
                    ],
                    [
                        'external_id' => 'skip-1',
                        'title' => 'Unrelated news',
                        'body' => 'Filtered out.',
                        'canonical_url' => 'https://example.com/posts/skip-1',
                    ],
                ],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'filter' => [
                'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
            ],
            'transform' => [
                'template' => 'News: {{title}}',
            ],
        ]);

        $result = $this->app->make(ExecutingProjectRunner::class)->preview($broadcast);

        $this->assertSame(2, $result->found);
        $this->assertSame(1, $result->wouldPublish);
        $this->assertSame(1, $result->wouldSkip);
        $this->assertTrue($result->items[0]->wouldPublish() || $result->items[1]->wouldPublish());
        $this->assertNotNull(collect($result->items)->first(fn ($item) => ! $item->wouldPublish())?->skipReason);
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, count($this->recordingDestination()->published()));
    }

    /**
     * @return array{User, Organization, Broadcast}
     */
    private function broadcastReadyForPreview(): array
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
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
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'filter' => [
                'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
            ],
            'transform' => [
                'template' => 'News: {{title}}',
            ],
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        return [$user, $organization, $broadcast];
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
