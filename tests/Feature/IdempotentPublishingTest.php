<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IdempotentPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_a_project_twice_publishes_only_once(): void
    {
        [$project] = $this->projectWithItem([
            'external_id' => 'once-1',
            'title' => 'Hello',
            'canonical_url' => 'https://example.com/once-1',
            'published_at' => '2026-03-15T12:00:00Z',
        ]);

        $this->runner()->run($project);
        $this->runner()->run($project);

        $recording = $this->recording();
        $this->assertCount(1, $recording->published());
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);

        $secondRun = ProjectRun::query()->where('project_id', $project->getKey())->orderByDesc('id')->first();
        $this->assertSame(RunStatus::Completed, $secondRun->status);
        $this->assertSame(1, $secondRun->items_found);
        $this->assertSame(0, $secondRun->items_new);
        $this->assertSame(1, $secondRun->items_skipped);
        $this->assertSame(0, $secondRun->items_delivered);
    }

    public function test_a_pending_delivery_from_a_crash_is_not_republished(): void
    {
        [$project, $broadcast, $destination] = $this->projectWithItem([
            'external_id' => 'crash-1',
            'title' => 'Crash',
            'canonical_url' => 'https://example.com/crash-1',
            'published_at' => '2026-03-15T12:00:00Z',
        ]);

        $this->runner()->run($project);
        $this->assertCount(1, $this->recording()->published());

        $delivery = ContentDelivery::query()->sole();
        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'external_reference' => null,
            'finished_at' => null,
        ])->save();

        $this->runner()->run($project);

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Pending, $delivery->fresh()->status);

        $secondRun = ProjectRun::query()->where('project_id', $project->getKey())->orderByDesc('id')->first();
        $this->assertSame(1, $secondRun->items_skipped);
        $this->assertSame(0, $secondRun->items_delivered);
        $this->assertSame($broadcast->getKey(), $delivery->broadcast_id);
        $this->assertSame($destination->getKey(), $delivery->destination_id);
    }

    public function test_the_delivery_route_unique_constraint_is_enforced(): void
    {
        [$project, $broadcast, $destination] = $this->projectWithItem([
            'external_id' => 'unique-1',
            'title' => 'Unique',
            'canonical_url' => 'https://example.com/unique-1',
            'published_at' => '2026-03-15T12:00:00Z',
        ]);

        $this->runner()->run($project);

        $item = ContentItem::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        ContentDelivery::query()->create([
            'content_item_id' => $item->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Pending,
            'attempted_at' => now(),
        ]);
    }

    public function test_a_new_broadcast_does_not_publish_the_source_back_catalogue(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    [
                        'external_id' => 'old-1',
                        'title' => 'Archive piece',
                        'canonical_url' => 'https://example.com/old-1',
                        'published_at' => '2026-01-01T12:00:00Z',
                    ],
                    [
                        'external_id' => 'new-1',
                        'title' => 'Fresh piece',
                        'canonical_url' => 'https://example.com/new-1',
                        'published_at' => '2026-03-15T11:00:00Z',
                    ],
                ],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-15 10:00:00', 'UTC'),
        ]);

        $this->runner()->run($project);

        $this->assertCount(1, $this->recording()->published());
        $this->assertStringStartsWith('Fresh piece', $this->recording()->published()[0]->body);

        $this->assertSame(DeliveryStatus::Skipped, ContentDelivery::query()
            ->whereHas('contentItem', fn ($query) => $query->where('external_id', 'old-1'))
            ->sole()
            ->status);
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()
            ->whereHas('contentItem', fn ($query) => $query->where('external_id', 'new-1'))
            ->sole()
            ->status);

        $run = ProjectRun::query()->sole();
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(1, $run->items_delivered);
    }

    public function test_a_content_hash_change_does_not_republish_an_existing_delivery(): void
    {
        [$project] = $this->projectWithItem([
            'external_id' => 'hash-1',
            'title' => 'Original',
            'body' => 'First body',
            'canonical_url' => 'https://example.com/hash-1',
            'published_at' => '2026-03-15T12:00:00Z',
        ]);

        $this->runner()->run($project);
        $this->assertCount(1, $this->recording()->published());

        $source = $project->sources()->sole();
        $source->config = [
            'feed_url' => 'https://example.com/feed.xml',
            'items' => [[
                'external_id' => 'hash-1',
                'title' => 'Updated',
                'body' => 'Changed body',
                'canonical_url' => 'https://example.com/hash-1',
                'published_at' => '2026-03-15T12:00:00Z',
            ]],
        ];
        $source->save();

        $this->runner()->run($project);

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame('Updated', ContentItem::query()->sole()->title);
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: Project, 1: Broadcast, 2: Destination}
     */
    private function projectWithItem(array $item): array
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [$item],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
        ]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ]);

        return [$project, $broadcast, $destination];
    }

    private function runner(): ExecutingProjectRunner
    {
        return $this->app->make(ExecutingProjectRunner::class);
    }

    private function recording(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
