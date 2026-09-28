<?php

namespace Tests\Feature;

use App\Enums\ContentItemLifecycle;
use App\Enums\DeliveryStatus;
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

class BroadcastSendLatestTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_send_latest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Send latest')
            ->assertSee(route('broadcast-send-latests.store', [$organization, $project]), false);
    }

    public function test_send_latest_publishes_backfill_item_that_run_now_skips(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'archive-1',
                    'title' => 'Archive piece',
                    'canonical_url' => 'https://example.com/archive-1',
                    'published_at' => '2026-01-01T12:00:00Z',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'transform' => ['template' => '{{title}}'],
            'created_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC'),
        ]);

        $recording = $this->recording();
        $this->app->make(ExecutingProjectRunner::class)->run($project);

        $this->assertCount(0, $recording->published());
        $this->assertSame(DeliveryStatus::Skipped, ContentDelivery::query()->sole()->status);
        $item = ContentItem::query()->sole();
        $this->assertSame(ContentItemLifecycle::Skipped, $item->lifecycle);

        $this->actingAs($user)
            ->from(route('broadcasts.edit', [$organization, $project]))
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('success');

        $this->assertCount(1, $recording->published());
        $this->assertStringStartsWith('Archive piece', $recording->published()[0]->body);
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
        $this->assertSame(ContentItemLifecycle::Delivered, $item->fresh()->lifecycle);
    }

    public function test_send_latest_only_targets_the_newest_item(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'transform' => ['template' => '{{title}}'],
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $older = ContentItem::factory()->for($project)->forSource((int) $source->getKey())->create([
            'title' => 'Older piece',
            'discovered_at' => now()->subHour(),
            'published_at' => now()->subDays(2),
            'lifecycle' => ContentItemLifecycle::Skipped,
        ]);
        $newer = ContentItem::factory()->for($project)->forSource((int) $source->getKey())->create([
            'title' => 'Newer piece',
            'discovered_at' => now(),
            'published_at' => now()->subDays(2),
            'lifecycle' => ContentItemLifecycle::Skipped,
        ]);

        ContentDelivery::factory()->create([
            'content_item_id' => $older->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Skipped,
            'error_detail' => 'Item was published before this broadcast existed.',
        ]);
        ContentDelivery::factory()->create([
            'content_item_id' => $newer->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Skipped,
            'error_detail' => 'Item was published before this broadcast existed.',
        ]);

        $recording = $this->recording();

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('success');

        $this->assertCount(1, $recording->published());
        $this->assertStringStartsWith('Newer piece', $recording->published()[0]->body);

        $this->assertTrue(
            ContentDelivery::query()
                ->where('content_item_id', $older->getKey())
                ->where('status', DeliveryStatus::Skipped)
                ->exists()
        );
        $this->assertSame(
            DeliveryStatus::Delivered,
            ContentDelivery::query()->where('content_item_id', $newer->getKey())->sole()->status
        );
        $this->assertSame(ContentItemLifecycle::Skipped, $older->fresh()->lifecycle);
        $this->assertSame(ContentItemLifecycle::Delivered, $newer->fresh()->lifecycle);
    }

    public function test_send_latest_with_no_items_explains_itself(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('error', 'No received source item to send.');
    }

    public function test_a_viewer_cannot_send_latest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_inactive_broadcast_cannot_send_latest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(); // draft

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('error', 'Only an active broadcast can send the latest item.');
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }

    private function recording(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
