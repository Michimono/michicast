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
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Fetches, filters, transforms and publishes each enabled broadcast on a
 * project, writing project_runs, broadcast_runs and content_deliveries.
 *
 * Publish is claim-before-send: a pending delivery row is inserted first so a
 * crash after the provider returns cannot cause a duplicate publish on the
 * next tick. The unique route constraint is the source of truth for duplicates.
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

            if ($this->isBackfill($item, $broadcast)) {
                $this->recordSkippedDelivery(
                    $broadcastRun,
                    $broadcast,
                    $item,
                    (int) $destination->getKey(),
                    'Item was published before this broadcast existed.',
                );
                $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
                $broadcastRun->items_skipped++;

                continue;
            }

            if ($this->deliveryBlocksRepublish($item, $broadcast, (int) $destination->getKey())) {
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

        $delivery = $this->claimDelivery($broadcastRun, $broadcast, $item, $destination->destinationId);

        if ($delivery === null) {
            $broadcastRun->items_skipped++;

            return;
        }

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
     * Insert a pending delivery before calling the provider. A unique
     * constraint hit means another claim already owns this route: skip rather
     * than risk a second publish. Failed rows may be reclaimed for a later
     * retry policy.
     */
    private function claimDelivery(
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        ContentItem $item,
        int $destinationId,
    ): ?ContentDelivery {
        try {
            return ContentDelivery::query()->create([
                'content_item_id' => $item->getKey(),
                'broadcast_id' => $broadcast->getKey(),
                'destination_id' => $destinationId,
                'broadcast_run_id' => $broadcastRun->getKey(),
                'status' => DeliveryStatus::Pending,
                'attempted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = ContentDelivery::query()
                ->where('content_item_id', $item->getKey())
                ->where('broadcast_id', $broadcast->getKey())
                ->where('destination_id', $destinationId)
                ->first();

            if ($existing === null) {
                return null;
            }

            if ($existing->status->blocksRepublish()) {
                return null;
            }

            $existing->fill([
                'broadcast_run_id' => $broadcastRun->getKey(),
                'status' => DeliveryStatus::Pending,
                'external_reference' => null,
                'failure' => null,
                'error_detail' => null,
                'retry_after_seconds' => null,
                'attempted_at' => now(),
                'finished_at' => null,
            ]);
            $existing->save();

            return $existing;
        }
    }

    private function recordSkippedDelivery(
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        ContentItem $item,
        int $destinationId,
        string $reason,
    ): void {
        try {
            ContentDelivery::query()->create([
                'content_item_id' => $item->getKey(),
                'broadcast_id' => $broadcast->getKey(),
                'destination_id' => $destinationId,
                'broadcast_run_id' => $broadcastRun->getKey(),
                'status' => DeliveryStatus::Skipped,
                'error_detail' => $reason,
                'attempted_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already recorded for this route; the run skip count is enough.
        }
    }

    /**
     * Prefer publishing nothing over flooding a new broadcast with the
     * source's archive. Uses published_at when the item has one, otherwise
     * discovered_at.
     */
    private function isBackfill(ContentItem $item, Broadcast $broadcast): bool
    {
        if ($broadcast->created_at === null) {
            return false;
        }

        $itemTime = $item->published_at ?? $item->discovered_at;

        return $itemTime->lt($broadcast->created_at);
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

    private function deliveryBlocksRepublish(ContentItem $item, Broadcast $broadcast, int $destinationId): bool
    {
        $existing = ContentDelivery::query()
            ->where('content_item_id', $item->getKey())
            ->where('broadcast_id', $broadcast->getKey())
            ->where('destination_id', $destinationId)
            ->first();

        return $existing !== null && $existing->status->blocksRepublish();
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
