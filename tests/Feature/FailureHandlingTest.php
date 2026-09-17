<?php

namespace Tests\Feature;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;
use App\Support\Clock;
use App\Support\DeliveryResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transient_failure_is_retried_within_the_run_and_can_succeed(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config([
            'michicast.retry.max_attempts' => 3,
            'michicast.retry.backoff_seconds' => 1,
        ]);

        $flaky = new class extends RecordingDestinationProvider
        {
            public int $attempts = 0;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $this->attempts++;

                if ($this->attempts < 3) {
                    return DeliveryResult::transientFailure('Temporary outage.', 1);
                }

                return parent::publish($destination, $message);
            }
        };
        $this->bindRecording($flaky);

        [$project] = $this->projectWithItem();

        $this->assertTrue($this->runner()->run($project, now()->addMinutes(5)));

        $this->assertSame(3, $flaky->attempts);
        $this->assertCount(1, $flaky->published());
        $this->assertSame(DeliveryStatus::Delivered, ContentDelivery::query()->sole()->status);
        $this->assertSame(0, $project->fresh()->consecutive_failures);
        $this->assertNotNull($project->fresh()->last_success_at);
        $this->assertSame(0, $project->destinations()->sole()->consecutive_failures);
        $this->assertSame(0, $project->broadcasts()->sole()->consecutive_failures);
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.retry.max_attempts' => 5]);

        $counting = new class extends RecordingDestinationProvider
        {
            public int $attempts = 0;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $this->attempts++;

                return DeliveryResult::permanentFailure('The chat was deleted.');
            }
        };
        $this->bindRecording($counting);

        [$project] = $this->projectWithItem();

        $this->runner()->run($project);
        $this->runner()->run($project);

        $this->assertSame(1, $counting->attempts);
        $this->assertCount(0, $counting->published());

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(DeliveryFailure::Permanent, $delivery->failure);
        $this->assertSame('The chat was deleted.', $delivery->error_detail);
        $this->assertTrue($delivery->blocksRepublish());
    }

    public function test_retries_stop_when_the_time_budget_would_be_exceeded(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config([
            'michicast.retry.max_attempts' => 5,
            'michicast.retry.backoff_seconds' => 30,
        ]);

        $counting = new class extends RecordingDestinationProvider
        {
            public int $attempts = 0;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $this->attempts++;

                return DeliveryResult::transientFailure('Slow.', 30);
            }
        };
        $this->bindRecording($counting);

        [$project] = $this->projectWithItem();

        $this->runner()->run($project, now()->addSeconds(10));

        $this->assertSame(1, $counting->attempts);
        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(DeliveryFailure::Transient, $delivery->failure);
    }

    public function test_a_transient_failure_remains_retryable_on_a_later_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.retry.max_attempts' => 1]);

        $provider = new class extends RecordingDestinationProvider
        {
            public bool $fail = true;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                if ($this->fail) {
                    return DeliveryResult::transientFailure('Not yet.');
                }

                return parent::publish($destination, $message);
            }
        };
        $this->bindRecording($provider);

        [$project] = $this->projectWithItem();

        $this->runner()->run($project);

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(DeliveryFailure::Transient, $delivery->failure);
        $this->assertFalse($delivery->blocksRepublish());

        $provider->fail = false;
        $this->runner()->run($project);

        $this->assertSame(DeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertCount(1, $provider->published());
    }

    public function test_consecutive_failures_pause_the_broadcast_at_the_threshold(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config([
            'michicast.failure.broadcast_pause_after' => 3,
            'michicast.retry.max_attempts' => 1,
        ]);

        $this->bindRecording(new class extends RecordingDestinationProvider
        {
            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                return DeliveryResult::transientFailure('Down.');
            }
        });

        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    $this->item('a'),
                    $this->item('b'),
                    $this->item('c'),
                    $this->item('d'),
                ],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ]);

        $this->runner()->run($project);

        $broadcast->refresh();
        $this->assertFalse($broadcast->enabled);
        $this->assertSame(3, $broadcast->consecutive_failures);
        $this->assertSame('Down.', $broadcast->failure_pause_reason);
        $this->assertSame(3, ContentDelivery::query()->count());
    }

    public function test_failures_below_the_pause_threshold_leave_the_broadcast_enabled(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config([
            'michicast.failure.broadcast_pause_after' => 5,
            'michicast.retry.max_attempts' => 1,
        ]);

        $this->bindRecording(new class extends RecordingDestinationProvider
        {
            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                return DeliveryResult::transientFailure('Down.');
            }
        });

        [$project, $broadcast] = $this->projectWithItem();

        $this->runner()->run($project);

        $broadcast->refresh();
        $this->assertTrue($broadcast->enabled);
        $this->assertSame(1, $broadcast->consecutive_failures);
        $this->assertNull($broadcast->failure_pause_reason);
    }

    public function test_a_success_resets_failure_counters(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.retry.max_attempts' => 1]);

        $provider = new class extends RecordingDestinationProvider
        {
            public bool $fail = true;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                if ($this->fail) {
                    return DeliveryResult::transientFailure('Down.');
                }

                return parent::publish($destination, $message);
            }
        };
        $this->bindRecording($provider);

        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [$this->item('one'), $this->item('two')],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-14 10:00:00', 'UTC'),
        ]);

        $this->runner()->run($project);

        $project->refresh();
        $destination->refresh();
        $broadcast = $project->broadcasts()->sole();
        $this->assertSame(2, $project->consecutive_failures);
        $this->assertSame(2, $destination->consecutive_failures);
        $this->assertSame(2, $broadcast->consecutive_failures);
        $this->assertNotNull($project->last_failure_at);

        $provider->fail = false;
        // New items so they are not blocked by failed deliveries of one/two —
        // reclaim those transient failures instead.
        $this->runner()->run($project);

        $project->refresh();
        $destination->refresh();
        $broadcast->refresh();
        $this->assertSame(0, $project->consecutive_failures);
        $this->assertSame(0, $destination->consecutive_failures);
        $this->assertSame(0, $broadcast->consecutive_failures);
        $this->assertNotNull($project->last_success_at);
        $this->assertSame(2, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_the_clock_advances_carbon_when_time_is_frozen(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $this->app->make(Clock::class)->sleep(5);

        $this->assertTrue(now()->equalTo(Carbon::parse('2026-03-15 10:00:05', 'UTC')));
    }

    /**
     * @return array{0: Project, 1: Broadcast, 2: Destination}
     */
    private function projectWithItem(): array
    {
        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [$this->item('item-1')],
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

    /**
     * @return array<string, mixed>
     */
    private function item(string $id): array
    {
        return [
            'external_id' => $id,
            'title' => 'Title '.$id,
            'canonical_url' => 'https://example.com/'.$id,
            'published_at' => '2026-03-15T12:00:00Z',
        ];
    }

    private function bindRecording(RecordingDestinationProvider $provider): void
    {
        $this->app->instance(RecordingDestinationProvider::class, $provider);
        $this->app->make(DestinationProviderRegistry::class)->register(
            'recording',
            RecordingDestinationProvider::class,
        );
    }

    private function runner(): ExecutingProjectRunner
    {
        return $this->app->make(ExecutingProjectRunner::class);
    }
}
