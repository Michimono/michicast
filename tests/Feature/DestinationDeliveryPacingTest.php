<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Jobs\SendScheduledDelivery;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationPacing;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\QuotaMeter;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DestinationDeliveryPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.retry.max_attempts' => 1]);
    }

    public function test_a_destination_without_a_rate_still_sends_immediately(): void
    {
        [$project] = $this->projectWithItems([
            $this->item('now-1', 'Immediate'),
        ]);

        $this->runner()->run($project);

        $delivery = ContentDelivery::query()->sole();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertNull($delivery->scheduled_for);
        $this->assertCount(1, $this->recording()->published());
    }

    public function test_a_rate_limit_spreads_deliveries_and_counts_quota_when_they_send(): void
    {
        Queue::fake();

        [$project] = $this->projectWithItems([
            $this->item('pace-1', 'First'),
            $this->item('pace-2', 'Second'),
            $this->item('pace-3', 'Third'),
        ], rateCount: 2, rateMinutes: 10);

        $this->runner()->run($project);

        Queue::assertNotPushed(SendScheduledDelivery::class);
        $this->assertCount(0, $this->recording()->published());
        $this->assertSame(0, $this->deliveriesUsed($project));

        $scheduled = ContentDelivery::query()->orderBy('scheduled_for')->orderBy('id')->get();

        $this->assertCount(3, $scheduled);
        $this->assertSame(
            ['First', 'Second', 'Third'],
            $scheduled->map(fn (ContentDelivery $delivery): string => (string) $delivery->contentItem?->title)->all(),
        );
        $this->assertSame(
            ['2026-03-15 10:00:00', '2026-03-15 10:05:00', '2026-03-15 10:10:00'],
            $scheduled->map(fn (ContentDelivery $delivery): string => $this->stamp($delivery))->all(),
        );
        $scheduled->each(function (ContentDelivery $delivery): void {
            $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        });

        $this->artisan('deliveries:send-due')->assertSuccessful();

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame(1, $this->deliveriesUsed($project));
        $this->assertSame(DeliveryStatus::Delivered, $scheduled[0]->fresh()->status);
        $this->assertSame(DeliveryStatus::Pending, $scheduled[1]->fresh()->status);
        $this->assertSame(DeliveryStatus::Pending, $scheduled[2]->fresh()->status);

        $this->travelTo(Carbon::parse('2026-03-15 10:05:00', 'UTC'));
        $this->artisan('deliveries:send-due')->assertSuccessful();

        $this->assertCount(2, $this->recording()->published());
        $this->assertSame(2, $this->deliveriesUsed($project));
        $this->assertSame(DeliveryStatus::Delivered, $scheduled[1]->fresh()->status);
        $this->assertSame(DeliveryStatus::Pending, $scheduled[2]->fresh()->status);
        $this->assertSame('2026-03-15 10:10:00', $this->stamp($scheduled[2]->fresh()));
    }

    public function test_an_early_send_waits_when_the_window_is_already_full(): void
    {
        [$project] = $this->projectWithItems([
            $this->item('win-1', 'First'),
            $this->item('win-2', 'Second'),
            $this->item('win-3', 'Third'),
        ], rateCount: 2, rateMinutes: 10);

        $this->runner()->run($project);
        $this->artisan('deliveries:send-due')->assertSuccessful();
        $this->travelTo(Carbon::parse('2026-03-15 10:05:00', 'UTC'));
        $this->artisan('deliveries:send-due')->assertSuccessful();

        $third = ContentDelivery::query()->where('status', DeliveryStatus::Pending)->sole();
        $third->scheduled_for = now();
        $third->save();

        $this->artisan('deliveries:send-due')->assertSuccessful();

        $this->assertCount(2, $this->recording()->published());
        $this->assertSame(DeliveryStatus::Pending, $third->fresh()->status);
        $this->assertTrue($third->fresh()->scheduled_for?->greaterThan(now()));
    }

    public function test_broadcasts_that_share_a_destination_share_one_queue(): void
    {
        $project = Project::factory()->due()->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'rate_limit_count' => 1,
            'rate_limit_minutes' => 10,
        ]);
        $firstSource = $this->source($project, [$this->item('shared-1', 'From the first broadcast')]);
        $secondSource = $this->source($project, [$this->item('shared-2', 'From the second broadcast')]);
        $first = $this->broadcast($project, $firstSource, $destination, 0);
        $second = $this->broadcast($project, $secondSource, $destination, 1);

        $this->runner()->run($project);

        $deliveries = ContentDelivery::query()->orderBy('scheduled_for')->orderBy('id')->get();

        $this->assertCount(2, $deliveries);
        $this->assertSame($first->getKey(), $deliveries[0]->broadcast_id);
        $this->assertSame($second->getKey(), $deliveries[1]->broadcast_id);
        $this->assertSame('2026-03-15 10:00:00', $this->stamp($deliveries[0]));
        $this->assertSame('2026-03-15 10:10:00', $this->stamp($deliveries[1]));
        $this->assertCount(0, $this->recording()->published());
        $this->assertSame(0, $this->deliveriesUsed($project));
    }

    public function test_a_full_queue_fails_the_delivery_without_counting_it_as_an_outage(): void
    {
        [$project, $broadcast, $destination] = $this->projectWithItems([
            $this->item('full-1', 'Kept'),
            $this->item('full-2', 'Turned away'),
            $this->item('full-3', 'Also turned away'),
        ], rateCount: 1, rateMinutes: 10, maxQueue: 1);

        $this->runner()->run($project);

        $kept = ContentDelivery::query()->where('status', DeliveryStatus::Pending)->sole();
        $failed = ContentDelivery::query()->where('status', DeliveryStatus::Failed)->orderBy('id')->get();

        $this->assertSame('Kept', $kept->contentItem?->title);
        $this->assertCount(2, $failed);
        $failed->each(function (ContentDelivery $delivery): void {
            $this->assertSame(DestinationPacing::QUEUE_FULL_REASON, $delivery->error_detail);
            $this->assertNull($delivery->scheduled_for);
        });
        $this->assertCount(0, $this->recording()->published());
        $this->assertSame(0, $this->deliveriesUsed($project));
        $this->assertSame(0, $destination->fresh()->consecutive_failures);
        $this->assertSame(0, $broadcast->fresh()->consecutive_failures);
        $this->assertTrue($broadcast->fresh()->enabled);
        $this->assertSame(0, $project->fresh()->consecutive_failures);

        $this->runner()->run($project->fresh());

        $this->assertSame(0, $destination->fresh()->consecutive_failures);
        $this->assertSame(0, $broadcast->fresh()->consecutive_failures);
        $this->assertTrue($broadcast->fresh()->enabled);
        $this->assertSame(2, ContentDelivery::query()->where('status', DeliveryStatus::Failed)->count());
    }

    public function test_a_retry_joins_the_same_queue_and_the_quota_is_counted_on_send(): void
    {
        [$project, , $destination] = $this->projectWithItems([
            $this->item('retry-1', 'Retry me'),
        ]);
        $destination->config = ['channel' => 'updates', 'outcome' => 'transient_failure'];
        $destination->save();

        $this->runner()->run($project);

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $usedAfterFailure = $this->deliveriesUsed($project);

        $destination->config = ['channel' => 'updates', 'outcome' => 'success'];
        $destination->rate_limit_count = 1;
        $destination->rate_limit_minutes = 10;
        $destination->save();
        $delivery->requestRetry();

        $this->runner()->run($project->fresh());

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame('2026-03-15 10:00:00', $this->stamp($delivery));
        $this->assertCount(0, $this->recording()->published());
        $this->assertSame($usedAfterFailure, $this->deliveriesUsed($project));

        $this->artisan('deliveries:send-due')->assertSuccessful();

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertCount(1, $this->recording()->published());
        $this->assertSame($usedAfterFailure + 1, $this->deliveriesUsed($project));
    }

    public function test_a_delayed_job_is_queued_when_the_queue_can_wait(): void
    {
        Queue::fake();
        config(['queue.default' => 'redis']);

        [$project] = $this->projectWithItems([
            $this->item('job-1', 'Held'),
        ], rateCount: 2, rateMinutes: 10);

        $this->runner()->run($project);

        $delivery = ContentDelivery::query()->sole();

        Queue::assertPushed(SendScheduledDelivery::class, function (SendScheduledDelivery $job) use ($delivery): bool {
            return $job->deliveryId === (int) $delivery->getKey() && $job->delay !== null;
        });
        $this->assertCount(0, $this->recording()->published());
    }

    public function test_the_delivery_list_shows_when_a_queued_message_will_send(): void
    {
        [$user, $organization] = $this->member();
        $project = Project::factory()->for($organization)->due()->create();
        $source = $this->source($project, []);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = $this->broadcast($project, $source, $destination, 0);
        $item = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Waiting note',
        ]);
        ContentDelivery::factory()->pending()->create([
            'content_item_id' => $item->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'scheduled_for' => Carbon::parse('2026-03-15 14:20:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Scheduled for')
            ->assertSee('14:20');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: Project, 1: Broadcast, 2: Destination}
     */
    private function projectWithItems(array $items, ?int $rateCount = null, ?int $rateMinutes = null, ?int $maxQueue = null): array
    {
        $project = Project::factory()->due()->create();
        $source = $this->source($project, $items);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'rate_limit_count' => $rateCount,
            'rate_limit_minutes' => $rateMinutes,
            'max_queue_size' => $maxQueue,
        ]);
        $broadcast = $this->broadcast($project, $source, $destination, 0);

        return [$project, $broadcast, $destination];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function source(Project $project, array $items): Source
    {
        return Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => $items,
            ],
        ]);
    }

    private function broadcast(Project $project, Source $source, Destination $destination, int $order): Broadcast
    {
        return Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'route_order' => $order,
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $id, string $title): array
    {
        return [
            'external_id' => $id,
            'title' => $title,
            'canonical_url' => 'https://example.com/'.$id,
            'published_at' => '2026-03-15T09:30:00Z',
        ];
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

    private function deliveriesUsed(Project $project): int
    {
        $project->load('organization');

        return $this->app->make(QuotaMeter::class)->used($project->organization, QuotaMetric::Deliveries);
    }

    private function stamp(ContentDelivery $delivery): string
    {
        return $delivery->scheduled_for?->timezone('UTC')->format('Y-m-d H:i:s') ?? '';
    }

    /**
     * @return array{User, Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        return [$user, Organization::factory()->withMember($user, Role::Viewer)->create()];
    }
}
