<?php

namespace App\Support;

use App\Enums\ContentItemLifecycle;
use App\Enums\DeliveryStatus;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Support\BroadcastFilters\BroadcastFilterEvaluator;
use App\Support\BroadcastTransforms\BroadcastTransformer;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Fetches, filters, transforms and publishes each enabled broadcast on a
 * project, writing project_runs, broadcast_runs and content_deliveries.
 */
class ExecutingProjectRunner implements ProjectRunner
{
    /**
     * Normalized items already fetched for a source within this run, keyed by
     * source id. Each entry is the persisted ContentItem plus whether persist
     * created a new row.
     *
     * @var array<int, list<array{item: ContentItem, isNew: bool}>>
     */
    private array $fetchedBySource = [];

    public function __construct(
        private SourceProviderRegistry $sources,
        private DestinationProviderRegistry $destinations,
        private BroadcastFilterEvaluator $filters,
        private BroadcastTransformer $transformer,
    ) {}

    public function run(Project $project, ?CarbonInterface $deadline = null): bool
    {
        $this->fetchedBySource = [];

        $projectRun = ProjectRun::query()->create([
            'project_id' => $project->getKey(),
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        $incomplete = false;

        $broadcasts = $project->broadcasts()
            ->runnable()
            ->with(['source', 'destination'])
            ->orderBy('id')
            ->get();

        foreach ($broadcasts as $broadcast) {
            if ($this->pastDeadline($deadline)) {
                $incomplete = true;
                break;
            }

            if (! $this->broadcastPartnersRunnable($broadcast)) {
                continue;
            }

            $finished = $this->runBroadcast($projectRun, $broadcast, $deadline);

            if (! $finished) {
                $incomplete = true;
                break;
            }
        }

        $this->rollUpCounts($projectRun);

        $projectRun->status = $incomplete ? RunStatus::Incomplete : RunStatus::Completed;
        $projectRun->finished_at = now();
        $projectRun->save();

        return ! $incomplete;
    }

    /**
     * @return bool False when the time budget ran out mid-broadcast.
     */
    private function runBroadcast(
        ProjectRun $projectRun,
        Broadcast $broadcast,
        ?CarbonInterface $deadline,
    ): bool {
        $broadcastRun = BroadcastRun::query()->create([
            'project_run_id' => $projectRun->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $entries = $this->itemsForSource($broadcast->source);
        } catch (Throwable $e) {
            report($e);
            $broadcastRun->status = RunStatus::Failed;
            $broadcastRun->error_detail = $e->getMessage();
            $broadcastRun->finished_at = now();
            $broadcastRun->save();

            return true;
        }

        $destination = $broadcast->destination;
        $provider = $this->destinations->get($destination->type);
        $sourceName = $broadcast->source->name;

        foreach ($entries as $entry) {
            if ($this->pastDeadline($deadline)) {
                $broadcastRun->status = RunStatus::Incomplete;
                $broadcastRun->finished_at = now();
                $broadcastRun->save();

                return false;
            }

            $item = $entry['item'];
            $broadcastRun->items_found++;

            if ($entry['isNew']) {
                $broadcastRun->items_new++;
            }

            if ($this->alreadyDelivered($item, $broadcast, $destination->getKey())) {
                $broadcastRun->items_skipped++;

                continue;
            }

            $decision = $this->filters->evaluate($broadcast->filter, $item);

            if (! $decision->passed) {
                $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
                $broadcastRun->items_skipped++;

                continue;
            }

            $this->advanceLifecycle($item, ContentItemLifecycle::Eligible);

            $message = $this->transformer->transform($item, $broadcast->transform, $sourceName);
            $this->advanceLifecycle($item, ContentItemLifecycle::Transformed);

            $this->publish($broadcastRun, $broadcast, $item, $provider, $destination->toDefinition(), $message);
        }

        $broadcastRun->status = RunStatus::Completed;
        $broadcastRun->finished_at = now();
        $broadcastRun->save();

        return true;
    }

    private function publish(
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        ContentItem $item,
        DestinationProvider $provider,
        DestinationDefinition $destination,
        PreparedMessage $message,
    ): void {
        $this->advanceLifecycle($item, ContentItemLifecycle::Delivering);

        $delivery = ContentDelivery::query()->firstOrNew([
            'content_item_id' => $item->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->destinationId,
        ]);

        $delivery->fill([
            'broadcast_run_id' => $broadcastRun->getKey(),
            'status' => DeliveryStatus::Pending,
            'external_reference' => null,
            'failure' => null,
            'error_detail' => null,
            'retry_after_seconds' => null,
            'attempted_at' => now(),
            'finished_at' => null,
        ]);
        $delivery->save();

        try {
            $result = $provider->publish($destination, $message);
        } catch (Throwable $e) {
            report($e);
            $delivery->status = DeliveryStatus::Failed;
            $delivery->error_detail = $e->getMessage();
            $delivery->finished_at = now();
            $delivery->save();
            $this->advanceLifecycle($item, ContentItemLifecycle::Failed);
            $broadcastRun->items_failed++;

            return;
        }

        if ($result->successful) {
            $delivery->status = DeliveryStatus::Delivered;
            $delivery->external_reference = $result->externalReference;
            $delivery->finished_at = now();
            $delivery->save();
            $this->advanceLifecycle($item, ContentItemLifecycle::Delivered);
            $broadcastRun->items_delivered++;

            return;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->failure = $result->failure;
        $delivery->error_detail = $result->failureReason;
        $delivery->retry_after_seconds = $result->retryAfterSeconds;
        $delivery->finished_at = now();
        $delivery->save();
        $this->advanceLifecycle($item, ContentItemLifecycle::Failed);
        $broadcastRun->items_failed++;
    }

    /**
     * @return list<array{item: ContentItem, isNew: bool}>
     */
    private function itemsForSource(Source $source): array
    {
        $sourceId = (int) $source->getKey();

        if (array_key_exists($sourceId, $this->fetchedBySource)) {
            return array_map(
                fn (array $entry): array => [
                    'item' => $entry['item'],
                    'isNew' => false,
                ],
                $this->fetchedBySource[$sourceId],
            );
        }

        $fetched = $this->sources->get($source->type)->fetch($source->toDefinition());
        $entries = [];

        foreach ($fetched as $normalized) {
            $persisted = $normalized->persist();
            $isNew = $persisted->wasRecentlyCreated;

            if ($isNew || $persisted->lifecycle === ContentItemLifecycle::Discovered) {
                $this->advanceLifecycle($persisted, ContentItemLifecycle::Normalized);
            }

            $entries[] = [
                'item' => $persisted,
                'isNew' => $isNew,
            ];
        }

        return $this->fetchedBySource[$sourceId] = $entries;
    }

    private function alreadyDelivered(ContentItem $item, Broadcast $broadcast, int $destinationId): bool
    {
        return ContentDelivery::query()
            ->where('content_item_id', $item->getKey())
            ->where('broadcast_id', $broadcast->getKey())
            ->where('destination_id', $destinationId)
            ->where('status', DeliveryStatus::Delivered)
            ->exists();
    }

    private function advanceLifecycle(ContentItem $item, ContentItemLifecycle $next): void
    {
        if ($item->lifecycle === $next) {
            return;
        }

        $item->lifecycle = $next;
        $item->save();
    }

    private function broadcastPartnersRunnable(Broadcast $broadcast): bool
    {
        $source = $broadcast->source;
        $destination = $broadcast->destination;

        return $source !== null
            && $destination !== null
            && $source->isRunnable()
            && $destination->isRunnable();
    }

    private function pastDeadline(?CarbonInterface $deadline): bool
    {
        return $deadline !== null && ! now()->lt($deadline);
    }

    private function rollUpCounts(ProjectRun $projectRun): void
    {
        $projectRun->items_found = (int) $projectRun->broadcastRuns()->sum('items_found');
        $projectRun->items_new = (int) $projectRun->broadcastRuns()->sum('items_new');
        $projectRun->items_skipped = (int) $projectRun->broadcastRuns()->sum('items_skipped');
        $projectRun->items_delivered = (int) $projectRun->broadcastRuns()->sum('items_delivered');
        $projectRun->items_failed = (int) $projectRun->broadcastRuns()->sum('items_failed');
    }
}
