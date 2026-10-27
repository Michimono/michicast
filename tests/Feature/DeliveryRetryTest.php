<?php

namespace Tests\Feature;

use App\Enums\DeliveryFailure;
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
use App\Support\DeliveryResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\PreparedMessage;
use App\Support\ProjectRunner;
use App\Support\RecordingDestinationProvider;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.retry.max_attempts' => 1]);
    }

    public function test_a_failed_delivery_retried_after_the_fix_publishes_once_and_updates_the_row(): void
    {
        [$user, $organization, $project, $broadcast, $destination] = $this->failedOnce();

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(DeliveryFailure::Transient, $delivery->failure);

        $this->fixDestination($destination);
        $this->dropItemFromFeed($project);

        $this->actingAs($user)
            ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertRedirect(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertSessionHas('success', 'The retry is queued for the next scheduler tick, within about a minute.');

        $this->assertNotNull($delivery->fresh()->retry_requested_at);
        $this->assertTrue($project->fresh()->next_run_at->equalTo(now()));

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertCount(1, $this->recording()->published());

        $retried = ContentDelivery::query()->sole();
        $this->assertSame($delivery->getKey(), $retried->getKey());
        $this->assertSame(DeliveryStatus::Delivered, $retried->status);
        $this->assertNull($retried->failure);
        $this->assertNull($retried->retry_requested_at);
        $this->assertSame('recording-1', $retried->external_reference);

        $attempts = $retried->attempts;
        $this->assertCount(2, $attempts);
        $this->assertSame(DeliveryStatus::Failed, $attempts[0]->status);
        $this->assertSame(DeliveryFailure::Transient, $attempts[0]->failure);
        $this->assertSame('The activity log did not answer in time.', $attempts[0]->error_detail);
        $this->assertFalse($attempts[0]->is_retry);
        $this->assertSame(DeliveryStatus::Delivered, $attempts[1]->status);
        $this->assertTrue($attempts[1]->is_retry);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('2 attempts')
            ->assertSee('The activity log did not answer in time.');
    }

    public function test_a_retry_never_creates_a_second_delivery_row_or_message(): void
    {
        [$user, $organization, , $broadcast, $destination] = $this->failedOnce();
        $delivery = ContentDelivery::query()->sole();
        $this->fixDestination($destination);

        $this->actingAs($user)->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]));
        $this->actingAs($user)->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]));
        $this->actingAs($user)->post(route('broadcast-delivery-retries.store', [$organization, $broadcast]));

        // The item is still in the feed, so the same run also walks it after the retry.
        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->actingAs($user)
            ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertSessionHas('error', 'Only a delivery that failed for a temporary reason can be retried.');

        $this->travel(1)->days();
        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertCount(1, $this->recording()->published());
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
        $this->assertSame(2, $delivery->attempts()->count());
    }

    public function test_permanent_failures_and_duplicates_are_not_retryable(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        [$project, $broadcast, $destination] = $this->route($organization);
        $project->next_run_at = now()->addDay();
        $project->save();

        $permanent = ContentDelivery::factory()->failed('Telegram rejected the message: chat not found')->create([
            'content_item_id' => $this->item($broadcast, 'Rejected item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);
        $duplicate = ContentDelivery::factory()->skipped('Already delivered, pending, or skipped for this destination.')->create([
            'content_item_id' => $this->item($broadcast, 'Duplicate item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->assertFalse($permanent->isRetryable());
        $this->assertFalse($duplicate->isRetryable());

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Rejected item')
            ->assertDontSee(route('delivery-retries.store', [$organization, $broadcast, $permanent]))
            ->assertDontSee(route('delivery-retries.store', [$organization, $broadcast, $duplicate]))
            ->assertDontSee('Retry all failed');

        foreach ([$permanent, $duplicate] as $delivery) {
            $this->actingAs($user)
                ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
                ->assertSessionHas('error', 'Only a delivery that failed for a temporary reason can be retried.');

            $this->assertNull($delivery->fresh()->retry_requested_at);
        }

        $this->actingAs($user)
            ->post(route('broadcast-delivery-retries.store', [$organization, $broadcast]))
            ->assertSessionHas('error', 'No failed delivery on this broadcast can be retried.');

        $this->assertTrue($project->fresh()->next_run_at->equalTo(now()->addDay()));
    }

    public function test_the_retry_request_makes_no_external_call(): void
    {
        Http::preventStrayRequests();

        $counting = new class extends RecordingDestinationProvider
        {
            public int $calls = 0;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $this->calls++;

                return parent::publish($destination, $message);
            }
        };
        $this->app->instance(RecordingDestinationProvider::class, $counting);
        $this->app->make(DestinationProviderRegistry::class)->register('recording', RecordingDestinationProvider::class);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        [, $broadcast, $destination] = $this->route($organization);
        $delivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'Waiting item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertSessionHas('success');
        $this->actingAs($user)
            ->post(route('broadcast-delivery-retries.store', [$organization, $broadcast]))
            ->assertSessionHas('error');

        $this->assertSame(0, $counting->calls);
        $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->retry_requested_at);
    }

    public function test_bulk_retry_queues_every_retryable_failure_for_one_run(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        [$project, $broadcast, $destination] = $this->route($organization);
        $project->next_run_at = now()->addDay();
        $project->save();

        $first = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'First')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);
        $second = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'Second')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);
        $permanent = ContentDelivery::factory()->failed()->create([
            'content_item_id' => $this->item($broadcast, 'Rejected')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertSee('Retry all failed')
            ->assertSee(route('delivery-retries.store', [$organization, $broadcast, $first]));

        $this->actingAs($user)
            ->post(route('broadcast-delivery-retries.store', [$organization, $broadcast]))
            ->assertSessionHas('success', 'Queued 2 failed deliveries for the next scheduler tick, within about a minute.');

        $this->assertNull($permanent->fresh()->retry_requested_at);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertCount(2, $this->recording()->published());
        $this->assertSame(DeliveryStatus::Delivered, $first->fresh()->status);
        $this->assertSame(DeliveryStatus::Delivered, $second->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $permanent->fresh()->status);
        $this->assertSame(3, ContentDelivery::query()->count());
        $this->assertSame(1, $project->runs()->count());
    }

    public function test_only_editors_and_above_can_retry(): void
    {
        [$viewer, $organization] = $this->memberHolding(Role::Viewer);
        [, $broadcast, $destination] = $this->route($organization);
        $delivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'Waiting item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($viewer)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertOk()
            ->assertDontSee(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertDontSee('Retry all failed');

        $this->actingAs($viewer)
            ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('broadcast-delivery-retries.store', [$organization, $broadcast]))
            ->assertForbidden();

        $this->assertNull($delivery->fresh()->retry_requested_at);
    }

    public function test_a_retry_needs_an_enabled_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        [, $broadcast, $destination] = $this->route($organization);
        $broadcast->pauseForFailures('Too many failures.');
        $delivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'Waiting item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->post(route('delivery-retries.store', [$organization, $broadcast, $delivery]))
            ->assertSessionHas('error', 'Enable this broadcast before retrying its deliveries.');

        $this->assertNull($delivery->fresh()->retry_requested_at);
    }

    public function test_a_retry_requested_during_a_run_keeps_the_project_due(): void
    {
        [, $organization] = $this->memberHolding(Role::Editor);
        [$project, $broadcast, $destination] = $this->route($organization);
        $delivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $this->item($broadcast, 'Waiting item')->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $runner = new class($delivery) implements ProjectRunner
        {
            public int $runs = 0;

            public function __construct(private ContentDelivery $delivery) {}

            /**
             * The first run sees a retry arrive mid-run; the second consumes it.
             */
            public function run(Project $project, ?CarbonInterface $deadline = null, ?array $sourceIds = null): bool
            {
                $this->runs++;

                if ($this->runs === 1) {
                    $this->delivery->requestRetry();
                } else {
                    $this->delivery->forceFill(['retry_requested_at' => null])->save();
                }

                return true;
            }
        };
        $this->app->instance(ProjectRunner::class, $runner);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame(2, $runner->runs);

        $project->refresh();
        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->gt(now()));
    }

    /**
     * Run the project once through the dispatcher with a destination that
     * times out, leaving one transient failure behind.
     *
     * @return array{User, Organization, Project, Broadcast, Destination}
     */
    private function failedOnce(): array
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        [$project, $broadcast, $destination] = $this->route($organization, [
            'external_id' => 'item-1',
            'title' => 'Launch notes',
            'canonical_url' => 'https://example.com/item-1',
            'published_at' => '2026-03-15T09:30:00Z',
        ]);
        $destination->config = ['channel' => 'updates', 'outcome' => 'transient_failure'];
        $destination->save();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertCount(0, $this->recording()->published());
        $this->assertTrue($project->fresh()->next_run_at->gt(now()));

        return [$user, $organization, $project, $broadcast, $destination];
    }

    /**
     * @param  array<string, mixed>|null  $feedItem
     * @return array{Project, Broadcast, Destination}
     */
    private function route(Organization $organization, ?array $feedItem = null): array
    {
        $project = Project::factory()->for($organization)->due()->create();
        $source = Source::factory()->for($project)->create([
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => $feedItem === null ? [] : [$feedItem],
            ],
        ]);
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ]);

        return [$project, $broadcast, $destination];
    }

    private function item(Broadcast $broadcast, string $title): ContentItem
    {
        return ContentItem::factory()->create([
            'project_id' => $broadcast->project_id,
            'source_id' => $broadcast->source_id,
            'title' => $title,
        ]);
    }

    private function fixDestination(Destination $destination): void
    {
        $destination->config = ['channel' => 'updates', 'outcome' => 'success'];
        $destination->save();
    }

    private function dropItemFromFeed(Project $project): void
    {
        $source = $project->sources()->sole();
        $source->config = ['feed_url' => 'https://example.com/feed.xml', 'items' => []];
        $source->save();
    }

    private function recording(): RecordingDestinationProvider
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
