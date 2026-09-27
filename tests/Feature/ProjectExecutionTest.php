<?php

namespace Tests\Feature;

use App\Enums\ContentItemLifecycle;
use App\Enums\DeliveryStatus;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Support\DeliveryResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\PreparedMessage;
use App\Support\ProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_run_fetches_filters_transforms_publishes_and_records_counts(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due()->create();
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
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'updates'],
        ]);
        Broadcast::factory()->create([
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

        $completed = $this->runner()->run($project);

        $this->assertTrue($completed);

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(2, $run->items_found);
        $this->assertSame(2, $run->items_new);
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(1, $run->items_delivered);
        $this->assertSame(0, $run->items_failed);
        $this->assertNotNull($run->finished_at);

        $broadcastRun = $run->broadcastRuns()->sole();
        $this->assertSame(RunStatus::Completed, $broadcastRun->status);
        $this->assertSame(1, $broadcastRun->items_delivered);
        $this->assertSame(1, $broadcastRun->items_skipped);

        $this->assertSame(2, ContentItem::query()->where('project_id', $project->getKey())->count());

        $kept = ContentItem::query()->where('external_id', 'keep-1')->sole();
        $skipped = ContentItem::query()->where('external_id', 'skip-1')->sole();
        $this->assertSame(ContentItemLifecycle::Delivered, $kept->lifecycle);
        $this->assertSame(ContentItemLifecycle::Skipped, $skipped->lifecycle);

        $delivery = ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame($kept->getKey(), $delivery->content_item_id);
        $this->assertSame($destination->getKey(), $delivery->destination_id);
        $this->assertNotNull($delivery->external_reference);

        $skippedDelivery = ContentDelivery::query()->where('status', DeliveryStatus::Skipped)->sole();
        $this->assertSame($skipped->getKey(), $skippedDelivery->content_item_id);
        $this->assertNotNull($skippedDelivery->error_detail);
        $this->assertStringContainsStringIgnoringCase('title', $skippedDelivery->error_detail);

        $recording = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $recording);
        $published = $recording->published();
        $this->assertCount(1, $published);
        $this->assertSame('News: Pokemon drop', $published[0]->body);
    }

    public function test_a_failing_broadcast_does_not_stop_the_others(): void
    {
        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Hello',
                    'canonical_url' => 'https://example.com/1',
                ]],
            ],
        ]);
        $okDestination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'ok', 'outcome' => 'success'],
        ]);
        $badDestination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'bad', 'outcome' => 'permanent_failure'],
        ]);

        $failing = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $badDestination->getKey(),
            'name' => 'Failing route',
        ]);
        $ok = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $okDestination->getKey(),
            'name' => 'Ok route',
        ]);

        $this->assertTrue($this->runner()->run($project));

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(2, $run->items_found);
        $this->assertSame(1, $run->items_delivered);
        $this->assertSame(1, $run->items_failed);

        $failingRun = $run->broadcastRuns()->where('broadcast_id', $failing->getKey())->sole();
        $okRun = $run->broadcastRuns()->where('broadcast_id', $ok->getKey())->sole();

        $this->assertSame(RunStatus::Completed, $failingRun->status);
        $this->assertSame(1, $failingRun->items_failed);
        $this->assertSame(RunStatus::Completed, $okRun->status);
        $this->assertSame(1, $okRun->items_delivered);

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(2, ContentDelivery::query()->count());
        $this->assertSame(1, ContentDelivery::query()->where('status', DeliveryStatus::Failed)->count());
        $this->assertSame(1, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_disabled_broadcasts_are_skipped_while_sources_and_destinations_always_participate(): void
    {
        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'from-source',
                    'title' => 'This runs',
                    'canonical_url' => 'https://example.com/only',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
        ]);
        $otherDestination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'other'],
        ]);

        Broadcast::factory()->disabled()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $otherDestination->getKey(),
        ]);

        $this->assertTrue($this->runner()->run($project));

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(1, $run->broadcastRuns()->count());
        $this->assertSame(1, $run->items_delivered);
        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame('from-source', ContentItem::query()->sole()->external_id);
        $this->assertSame(1, ContentDelivery::query()->count());
        $this->assertSame($otherDestination->getKey(), ContentDelivery::query()->sole()->destination_id);
    }

    public function test_a_paused_project_is_not_dispatched(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->paused()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'paused-item',
                    'title' => 'Should not run',
                    'canonical_url' => 'https://example.com/paused',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame(0, ProjectRun::query()->where('project_id', $project->getKey())->count());
        $this->assertSame(0, ContentItem::query()->count());
        $this->assertSame(0, ContentDelivery::query()->count());
    }

    public function test_shared_sources_are_fetched_once_per_run(): void
    {
        $project = Project::factory()->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'shared-1',
                    'title' => 'Shared',
                    'canonical_url' => 'https://example.com/shared',
                ]],
            ],
        ]);
        $firstDestination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'one'],
        ]);
        $secondDestination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'two'],
        ]);

        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $firstDestination->getKey(),
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $secondDestination->getKey(),
        ]);

        $this->assertTrue($this->runner()->run($project));

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame(2, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());

        $run = ProjectRun::query()->sole();
        $this->assertSame(2, $run->items_found);
        $this->assertSame(1, $run->items_new);
        $this->assertSame(2, $run->items_delivered);
    }

    public function test_exceeding_the_time_budget_ends_the_run_and_leaves_the_project_due(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $slow = new class extends RecordingDestinationProvider
        {
            public ?TestCase $test = null;

            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $result = parent::publish($destination, $message);
                $this->test?->travel(90)->seconds();

                return $result;
            }
        };
        $slow->test = $this;
        $this->app->instance(RecordingDestinationProvider::class, $slow);
        $this->app->make(DestinationProviderRegistry::class)->register(
            'recording',
            RecordingDestinationProvider::class,
        );

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Hello',
                    'canonical_url' => 'https://example.com/1',
                ]],
            ],
        ]);
        $firstDestination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $secondDestination = Destination::factory()->create(['project_id' => $project->getKey()]);

        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $firstDestination->getKey(),
            'name' => 'First',
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $secondDestination->getKey(),
            'name' => 'Second',
        ]);

        config(['michicast.dispatch.time_budget' => 60]);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $project->refresh();
        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();

        $this->assertSame(RunStatus::Incomplete, $run->status);
        $this->assertSame(1, $run->broadcastRuns()->count());
        $this->assertSame(1, $run->items_delivered);
        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 10:01:30', 'UTC')));
    }

    public function test_the_application_binds_the_executing_project_runner(): void
    {
        $this->assertInstanceOf(ExecutingProjectRunner::class, $this->app->make(ProjectRunner::class));
    }

    private function runner(): ExecutingProjectRunner
    {
        return $this->app->make(ExecutingProjectRunner::class);
    }
}
