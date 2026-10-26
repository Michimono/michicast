<?php

namespace App\Support;

use App\Enums\ContentItemLifecycle;
use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use App\Enums\QuotaMetric;
use App\Enums\RunStatus;
use App\Exceptions\QuotaExceededException;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\BroadcastRunSkip;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Support\BroadcastFilters\BroadcastFilterEvaluator;
use App\Support\Mapping\ContentMapper;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches, filters, transforms and publishes each enabled broadcast on a
 * project, writing project_runs, broadcast_runs and content_deliveries.
 *
 * Publish is claim-before-send: a pending delivery row is inserted first so a
 * crash after the provider returns cannot cause a duplicate publish on the
 * next tick. The unique route constraint is the source of truth for duplicates.
 *
 * Transient destination failures are retried inline within the dispatch time
 * budget; permanent failures are recorded once and never attempted again.
 *
 * Preview and dry-run reuse the same filter and transform path with publishing
 * disabled. Preview never persists items, deliveries or lifecycle; dry-run
 * records a ProjectRun marked is_dry and a per-broadcast dry_report.
 *
 * publishSingleItem supports Send latest: one persisted item, no feed walk,
 * optional backfill bypass after the caller clears prior deliveries.
 *
 * Retries queued from the delivery history run at the start of each broadcast
 * on a normal run: transform again with the current configuration, then the
 * same claim-before-send publish, which reclaims the existing transient
 * failure row instead of inserting a second one. Every publish outcome is
 * appended as a ContentDeliveryAttempt so earlier failures stay visible.
 */
class ExecutingProjectRunner implements ProjectRunner
{
    /**
     * One external fetch per source for this run, keyed by source id. Later
     * broadcasts that use the same source reuse these persisted rows instead
     * of calling the provider again. The next run fetches once more so new
     * posts are seen, then stores them on the same rows.
     *
     * @var array<int, list<array{item: ContentItem, isNew: bool}>>
     */
    private array $fetchedBySource = [];

    private bool $dry = false;

    public function __construct(
        private SourceProviderRegistry $sources,
        private DestinationProviderRegistry $destinations,
        private BroadcastFilterEvaluator $filters,
        private ContentMapper $mapper,
        private Clock $clock,
        private SecretRedactor $secrets,
        private QuotaMeter $meter,
    ) {}

    public function run(Project $project, ?CarbonInterface $deadline = null): bool
    {
        $this->dry = false;
        $run = $this->execute($project, $deadline);

        return $run->status !== RunStatus::Incomplete;
    }

    /**
     * Walk every enabled broadcast with publishing disabled and record the
     * outcome as a dry project run.
     */
    public function dryRun(Project $project): ProjectRun
    {
        $this->dry = true;

        return $this->execute($project, null);
    }

    /**
     * Publish a single already-persisted content item on each matching runnable
     * broadcast. Skips the source fetch and, when $bypassBackfill is true, the
     * backfill guard. Prior delivery blockers must be cleared by the caller.
     * Broadcast filters still apply.
     */
    public function publishSingleItem(
        Project $project,
        ContentItem $item,
        bool $bypassBackfill = false,
    ): ProjectRun {
        $this->dry = false;
        $this->fetchedBySource = [];

        $projectRun = ProjectRun::query()->create([
            'project_id' => $project->getKey(),
            'status' => RunStatus::Running,
            'is_dry' => false,
            'started_at' => now(),
        ]);

        $broadcasts = $project->broadcasts()
            ->runnable()
            ->where('source_id', $item->source_id)
            ->with(['source', 'destination'])
            ->orderBy('id')
            ->get();

        foreach ($broadcasts as $broadcast) {
            if (! $this->broadcastPartnersRunnable($broadcast)) {
                continue;
            }

            $this->publishSingleItemOnBroadcast($project, $projectRun, $broadcast, $item, $bypassBackfill);
        }

        $this->rollUpCounts($projectRun);

        $projectRun->status = $this->rollUpStatus($projectRun, incomplete: false);
        $projectRun->finished_at = now();
        $projectRun->save();

        return $projectRun;
    }

    private function publishSingleItemOnBroadcast(
        Project $project,
        ProjectRun $projectRun,
        Broadcast $broadcast,
        ContentItem $item,
        bool $bypassBackfill,
    ): void {
        $broadcastRun = BroadcastRun::query()->create([
            'project_run_id' => $projectRun->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'status' => RunStatus::Running,
            'started_at' => now(),
            'dry_report' => null,
        ]);

        $destination = $broadcast->destination;
        $provider = $this->destinations->get($destination->type);
        $sourceName = $broadcast->source->name;
        $broadcastRun->items_found = 1;

        if (! $bypassBackfill && $this->isBackfill($item, $broadcast)) {
            $this->recordOutcome(
                $broadcastRun,
                $broadcast,
                $item,
                (int) $destination->getKey(),
                'skip',
                'Item was published before this broadcast existed.',
                createSkippedDelivery: true,
            );
            $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
            $broadcastRun->items_skipped++;
            $broadcastRun->status = RunStatus::Completed;
            $broadcastRun->finished_at = now();
            $broadcastRun->save();

            return;
        }

        $decision = $this->filters->evaluate($broadcast->filter, $item);

        if (! $decision->passed) {
            $reason = $decision->skipReason ?? 'Filtered out by a broadcast rule.';
            $this->recordOutcome(
                $broadcastRun,
                $broadcast,
                $item,
                (int) $destination->getKey(),
                'skip',
                $reason,
                createSkippedDelivery: true,
            );
            $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
            $broadcastRun->items_skipped++;
            $broadcastRun->status = RunStatus::Completed;
            $broadcastRun->finished_at = now();
            $broadcastRun->save();

            return;
        }

        $this->advanceLifecycle($item, ContentItemLifecycle::Eligible);

        $message = $this->mapper->prepare($broadcast, $item, $sourceName);
        $this->advanceLifecycle($item, ContentItemLifecycle::Transformed);

        try {
            $this->publish(
                $project,
                $broadcastRun,
                $broadcast,
                $destination,
                $item,
                $provider,
                $destination->toDefinition(),
                $message,
                null,
            );

            $broadcastRun->status = RunStatus::Completed;
            $broadcastRun->finished_at = now();
            $broadcastRun->save();
        } catch (QuotaExceededException $e) {
            $this->finishBroadcastRunOutOfQuota($broadcastRun, $broadcast, $project, $e);
        }
    }

    /**
     * Fetch from the broadcast's source, apply filters and transforms, and
     * return what would publish — without writing items, deliveries or
     * lifecycle changes.
     */
    public function preview(Broadcast $broadcast, ?int $limit = null): BroadcastPreviewResult
    {
        $limit ??= max(1, (int) config('michicast.preview.item_limit', 5));

        $broadcast->loadMissing(['source.project.organization', 'destination']);

        // Meter before the provider call; on denial skip the fetch and surface Out of quota.
        $organization = $this->organizationForSource($broadcast->source);
        $decision = $this->meter->consume($organization, QuotaMetric::SourceFetches);

        if (! $decision->allowed) {
            throw new QuotaExceededException($decision, QuotaMetric::SourceFetches);
        }

        $fetched = $this->sources->get($broadcast->source->type)->fetch($broadcast->source->toDefinition());

        usort($fetched, function (ContentItem $a, ContentItem $b): int {
            $aTime = $a->published_at ?? $a->discovered_at;
            $bTime = $b->published_at ?? $b->discovered_at;

            return $bTime <=> $aTime;
        });

        $fetched = array_slice($fetched, 0, $limit);
        $sourceName = $broadcast->source->name;
        $destinationId = (int) $broadcast->destination->getKey();
        $items = [];
        $wouldPublish = 0;
        $wouldSkip = 0;

        foreach ($fetched as $item) {
            if ($this->isBackfill($item, $broadcast)) {
                $items[] = new BroadcastPreviewItem(
                    title: $this->itemLabel($item),
                    body: $item->body,
                    canonicalUrl: $item->canonical_url,
                    outcome: 'skip',
                    skipReason: 'Item was published before this broadcast existed.',
                );
                $wouldSkip++;

                continue;
            }

            if ($this->previewDeliveryBlocksRepublish($item, $broadcast, $destinationId)) {
                $items[] = new BroadcastPreviewItem(
                    title: $this->itemLabel($item),
                    body: $item->body,
                    canonicalUrl: $item->canonical_url,
                    outcome: 'skip',
                    skipReason: 'Already delivered, pending, or skipped for this destination.',
                );
                $wouldSkip++;

                continue;
            }

            $decision = $this->filters->evaluate($broadcast->filter, $item);

            if (! $decision->passed) {
                $items[] = new BroadcastPreviewItem(
                    title: $this->itemLabel($item),
                    body: $item->body,
                    canonicalUrl: $item->canonical_url,
                    outcome: 'skip',
                    skipReason: $decision->skipReason ?? 'Filtered out by a broadcast rule.',
                );
                $wouldSkip++;

                continue;
            }

            $message = $this->mapper->prepare($broadcast, $item, $sourceName);
            $items[] = new BroadcastPreviewItem(
                title: $this->itemLabel($item),
                body: $item->body,
                canonicalUrl: $item->canonical_url,
                outcome: 'publish',
                rendered: $message->body,
            );
            $wouldPublish++;
        }

        return new BroadcastPreviewResult(
            items: $items,
            found: count($items),
            wouldPublish: $wouldPublish,
            wouldSkip: $wouldSkip,
        );
    }

    private function execute(Project $project, ?CarbonInterface $deadline): ProjectRun
    {
        $this->fetchedBySource = [];

        $projectRun = ProjectRun::query()->create([
            'project_id' => $project->getKey(),
            'status' => RunStatus::Running,
            'is_dry' => $this->dry,
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

            $project->loadMissing('organization');
            $project->organization->refresh();

            // Stop cleanly mid-run when the org is disabled after this job started.
            if ($project->organization->isDisabled()) {
                break;
            }

            if (! $this->broadcastPartnersRunnable($broadcast)) {
                continue;
            }

            $finished = $this->runBroadcast($project, $projectRun, $broadcast, $deadline);

            if (! $finished) {
                $incomplete = true;
                break;
            }
        }

        $this->rollUpCounts($projectRun);

        $projectRun->status = $this->rollUpStatus($projectRun, $incomplete);
        $projectRun->finished_at = now();
        $projectRun->save();

        return $projectRun;
    }

    /**
     * @return bool False when the time budget ran out mid-broadcast.
     */
    private function runBroadcast(
        Project $project,
        ProjectRun $projectRun,
        Broadcast $broadcast,
        ?CarbonInterface $deadline,
    ): bool {
        $broadcastRun = BroadcastRun::query()->create([
            'project_run_id' => $projectRun->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'status' => RunStatus::Running,
            'started_at' => now(),
            'dry_report' => $this->dry ? [] : null,
        ]);

        $retriedItemIds = [];

        if (! $this->dry) {
            try {
                $retriedItemIds = $this->retryRequestedDeliveries($project, $broadcastRun, $broadcast, $deadline);
            } catch (QuotaExceededException $e) {
                $this->finishBroadcastRunOutOfQuota($broadcastRun, $broadcast, $project, $e);

                return true;
            }

            if ($this->pastDeadline($deadline)) {
                $broadcastRun->status = RunStatus::Incomplete;
                $broadcastRun->finished_at = now();
                $broadcastRun->save();

                return false;
            }
        }

        try {
            $entries = $this->itemsForSource($broadcast->source);

            if (! $this->dry) {
                $broadcast->source->recordSuccess();
            }
        } catch (QuotaExceededException $e) {
            $this->finishBroadcastRunOutOfQuota($broadcastRun, $broadcast, $project, $e);

            return true;
        } catch (Throwable $e) {
            report($e);

            if (! $this->dry) {
                $broadcast->source->recordFailure();
                $project->recordFailure();
                $broadcast->recordFailure((string) $this->secrets->redact($e->getMessage()));
            }

            $broadcastRun->status = RunStatus::Failed;
            $broadcastRun->error_detail = $this->secrets->redact($e->getMessage());
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

            if (! $broadcast->fresh()->isRunnable()) {
                break;
            }

            $item = $entry['item'];
            $broadcastRun->items_found++;

            if ($entry['isNew']) {
                $broadcastRun->items_new++;
            }

            if (in_array((int) $item->getKey(), $retriedItemIds, true)) {
                $this->recordRunSkip($broadcastRun, $item, 'Retried earlier in this run.');
                $broadcastRun->items_skipped++;

                continue;
            }

            if ($this->isBackfill($item, $broadcast)) {
                $this->recordOutcome(
                    $broadcastRun,
                    $broadcast,
                    $item,
                    (int) $destination->getKey(),
                    'skip',
                    'Item was published before this broadcast existed.',
                    createSkippedDelivery: true,
                );
                $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
                $broadcastRun->items_skipped++;

                continue;
            }

            if ($this->deliveryBlocksRepublish($item, $broadcast, (int) $destination->getKey())) {
                $this->recordOutcome(
                    $broadcastRun,
                    $broadcast,
                    $item,
                    (int) $destination->getKey(),
                    'skip',
                    'Already delivered, pending, or skipped for this destination.',
                    createSkippedDelivery: false,
                );
                $broadcastRun->items_skipped++;

                continue;
            }

            $decision = $this->filters->evaluate($broadcast->filter, $item);

            if (! $decision->passed) {
                $reason = $decision->skipReason ?? 'Filtered out by a broadcast rule.';
                $this->recordOutcome(
                    $broadcastRun,
                    $broadcast,
                    $item,
                    (int) $destination->getKey(),
                    'skip',
                    $reason,
                    createSkippedDelivery: true,
                );
                $this->advanceLifecycle($item, ContentItemLifecycle::Skipped);
                $broadcastRun->items_skipped++;

                continue;
            }

            $this->advanceLifecycle($item, ContentItemLifecycle::Eligible);

            $message = $this->mapper->prepare($broadcast, $item, $sourceName);
            $this->advanceLifecycle($item, ContentItemLifecycle::Transformed);

            if ($this->dry) {
                $this->appendDryReport($broadcastRun, $item, 'publish', rendered: $message->body);
                $broadcastRun->items_delivered++;

                continue;
            }

            try {
                $this->publish(
                    $project,
                    $broadcastRun,
                    $broadcast,
                    $destination,
                    $item,
                    $provider,
                    $destination->toDefinition(),
                    $message,
                    $deadline,
                );
            } catch (QuotaExceededException $e) {
                $this->finishBroadcastRunOutOfQuota($broadcastRun, $broadcast, $project, $e);

                return true;
            }
        }

        $broadcastRun->status = RunStatus::Completed;
        $broadcastRun->finished_at = now();
        $broadcastRun->save();

        return true;
    }

    /**
     * Republish the routes of this broadcast that were queued for a retry.
     *
     * Each request is consumed before anything is sent, so a crash cannot
     * leave it queued forever. Requests on rows that stopped being retryable
     * (delivered meanwhile, permanent, or moved to another destination) are
     * dropped without a publish.
     *
     * @return list<int> Content item ids that were republished.
     */
    private function retryRequestedDeliveries(
        Project $project,
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        ?CarbonInterface $deadline,
    ): array {
        $deliveries = $broadcast->deliveries()
            ->whereNotNull('retry_requested_at')
            ->with('contentItem')
            ->orderBy('retry_requested_at')
            ->orderBy('id')
            ->get();

        $destination = $broadcast->destination;
        $retried = [];

        foreach ($deliveries as $delivery) {
            if ($this->pastDeadline($deadline)) {
                break;
            }

            $delivery->retry_requested_at = null;
            $delivery->save();
            $delivery->setRelation('broadcast', $broadcast);

            $item = $delivery->contentItem;

            if ($item === null || ! $delivery->isRetryable()) {
                continue;
            }

            $message = $this->mapper->prepare($broadcast, $item, $broadcast->source->name);
            $this->advanceLifecycle($item, ContentItemLifecycle::Transformed);

            $this->publish(
                $project,
                $broadcastRun,
                $broadcast,
                $destination,
                $item,
                $this->destinations->get($destination->type),
                $destination->toDefinition(),
                $message,
                $deadline,
                isRetry: true,
            );

            $retried[] = (int) $item->getKey();
        }

        return $retried;
    }

    private function publish(
        Project $project,
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        Destination $destination,
        ContentItem $item,
        DestinationProvider $provider,
        DestinationDefinition $definition,
        PreparedMessage $message,
        ?CarbonInterface $deadline,
        bool $isRetry = false,
    ): void {
        $this->advanceLifecycle($item, ContentItemLifecycle::Delivering);

        $delivery = $this->claimDelivery($broadcastRun, $broadcast, $item, $definition->destinationId);

        if ($delivery === null) {
            $this->recordRunSkip(
                $broadcastRun,
                $item,
                'Already delivered, pending, or skipped for this destination.',
            );
            $broadcastRun->items_skipped++;

            return;
        }

        $maxAttempts = max(1, (int) config('michicast.retry.max_attempts'));
        $defaultBackoff = max(0, (int) config('michicast.retry.backoff_seconds'));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->pastDeadline($deadline)) {
                $this->markDeliveryFailed(
                    $delivery,
                    $item,
                    $broadcastRun,
                    $project,
                    $broadcast,
                    $destination,
                    DeliveryFailure::Transient,
                    'The dispatch time budget ran out before the delivery could finish.',
                    null,
                    $isRetry,
                );

                return;
            }

            // Retries count as separate delivery attempts; dry runs never reach publish().
            // On denial stop cleanly: no provider call, no failure counter, no hanging pending row.
            $project->loadMissing('organization');
            $organization = $project->organization;
            $decision = $this->meter->consume($organization, QuotaMetric::Deliveries);

            if (! $decision->allowed) {
                $this->abandonDeliveryForQuota($delivery, $item, $decision);

                throw new QuotaExceededException($decision, QuotaMetric::Deliveries);
            }

            try {
                $result = $provider->publish($definition, $message);
            } catch (Throwable $e) {
                report($e);
                $result = DeliveryResult::transientFailure((string) $this->secrets->redact($e->getMessage()));
            }

            if ($result->successful) {
                $delivery->status = DeliveryStatus::Delivered;
                $delivery->external_reference = $result->externalReference;
                $delivery->published_message = $result->publishedMessage;
                $delivery->published_payload = $result->publishedPayload === null ? null : [
                    'content_item' => [
                        'id' => $item->getKey(),
                        'source_id' => $item->source_id,
                        'external_id' => $item->external_id,
                        'canonical_url' => $item->canonical_url,
                    ],
                    'broadcast_id' => $broadcast->getKey(),
                    'attempt' => $attempt,
                    ...$result->publishedPayload,
                ];
                $delivery->failure = null;
                $delivery->error_detail = null;
                $delivery->retry_after_seconds = null;
                $delivery->finished_at = now();
                $delivery->save();
                $this->recordAttempt($delivery, $isRetry);
                $this->advanceLifecycle($item, ContentItemLifecycle::Delivered);
                $broadcastRun->items_delivered++;
                $destination->recordSuccess();
                $broadcast->recordSuccess();
                $project->recordSuccess();

                return;
            }

            if (! $result->isRetryable()) {
                $this->markDeliveryFailed(
                    $delivery,
                    $item,
                    $broadcastRun,
                    $project,
                    $broadcast,
                    $destination,
                    $result->failure ?? DeliveryFailure::Permanent,
                    (string) $result->failureReason,
                    $result->retryAfterSeconds,
                    $isRetry,
                );

                return;
            }

            if ($attempt >= $maxAttempts) {
                $this->markDeliveryFailed(
                    $delivery,
                    $item,
                    $broadcastRun,
                    $project,
                    $broadcast,
                    $destination,
                    DeliveryFailure::Transient,
                    (string) $result->failureReason,
                    $result->retryAfterSeconds,
                    $isRetry,
                );

                return;
            }

            $wait = $result->retryAfterSeconds ?? $defaultBackoff;

            if (! $this->canWait($wait, $deadline)) {
                $this->markDeliveryFailed(
                    $delivery,
                    $item,
                    $broadcastRun,
                    $project,
                    $broadcast,
                    $destination,
                    DeliveryFailure::Transient,
                    (string) $result->failureReason,
                    $result->retryAfterSeconds,
                    $isRetry,
                );

                return;
            }

            $this->clock->sleep($wait);
        }
    }

    private function markDeliveryFailed(
        ContentDelivery $delivery,
        ContentItem $item,
        BroadcastRun $broadcastRun,
        Project $project,
        Broadcast $broadcast,
        Destination $destination,
        DeliveryFailure $failure,
        string $reason,
        ?int $retryAfterSeconds,
        bool $isRetry,
    ): void {
        $reason = (string) $this->secrets->redact($reason);
        $delivery->status = DeliveryStatus::Failed;
        $delivery->failure = $failure;
        $delivery->error_detail = $reason;
        $delivery->retry_after_seconds = $retryAfterSeconds;
        $delivery->finished_at = now();
        $delivery->save();
        $this->recordAttempt($delivery, $isRetry);
        $this->advanceLifecycle($item, ContentItemLifecycle::Failed);
        $broadcastRun->items_failed++;
        $destination->recordFailure();
        $project->recordFailure();
        $broadcast->recordFailure($reason);
    }

    /**
     * Append the finished outcome of the delivery as one attempt. The row
     * itself only keeps the latest state of the route.
     */
    private function recordAttempt(ContentDelivery $delivery, bool $isRetry): void
    {
        $delivery->attempts()->create([
            'broadcast_run_id' => $delivery->broadcast_run_id,
            'status' => $delivery->status,
            'failure' => $delivery->failure,
            'error_detail' => $delivery->error_detail,
            'external_reference' => $delivery->external_reference,
            'is_retry' => $isRetry,
            'attempted_at' => $delivery->attempted_at,
            'finished_at' => $delivery->finished_at,
        ]);
    }

    private function canWait(int $seconds, ?CarbonInterface $deadline): bool
    {
        if ($deadline === null) {
            return true;
        }

        if ($seconds <= 0) {
            return ! $this->pastDeadline($deadline);
        }

        return now()->addSeconds($seconds)->lt($deadline);
    }

    /**
     * Insert a pending delivery before calling the provider. A unique
     * constraint hit means another claim already owns this route: skip rather
     * than risk a second publish. Transient failed rows may be reclaimed; the
     * reclaim is a conditional update, so two claims racing for the same
     * failed row cannot both win.
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

            if ($existing === null || $existing->blocksRepublish()) {
                return null;
            }

            $reclaimed = ContentDelivery::query()
                ->whereKey($existing->getKey())
                ->where('status', DeliveryStatus::Failed->value)
                ->where(function (Builder $query): void {
                    $query->whereNull('failure')
                        ->orWhere('failure', '!=', DeliveryFailure::Permanent->value);
                })
                ->update([
                    'broadcast_run_id' => $broadcastRun->getKey(),
                    'status' => DeliveryStatus::Pending->value,
                    'external_reference' => null,
                    'failure' => null,
                    'error_detail' => null,
                    'retry_after_seconds' => null,
                    'retry_requested_at' => null,
                    'attempted_at' => now(),
                    'finished_at' => null,
                ]);

            if ($reclaimed !== 1) {
                return null;
            }

            return $existing->refresh();
        }
    }

    /**
     * @param  'publish'|'skip'  $outcome
     */
    private function recordOutcome(
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        ContentItem $item,
        int $destinationId,
        string $outcome,
        string $reason,
        bool $createSkippedDelivery,
    ): void {
        if ($this->dry) {
            $this->appendDryReport($broadcastRun, $item, $outcome, skipReason: $reason);

            return;
        }

        if ($createSkippedDelivery) {
            $this->recordSkippedDelivery($broadcastRun, $broadcast, $item, $destinationId, $reason);

            return;
        }

        $this->recordRunSkip($broadcastRun, $item, $reason);
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
            $this->recordRunSkip($broadcastRun, $item, $reason);
        }
    }

    private function recordRunSkip(BroadcastRun $broadcastRun, ContentItem $item, string $reason): void
    {
        BroadcastRunSkip::query()->create([
            'broadcast_run_id' => $broadcastRun->getKey(),
            'content_item_id' => $item->getKey(),
            'reason' => $reason,
        ]);
    }

    /**
     * @param  'publish'|'skip'  $outcome
     */
    private function appendDryReport(
        BroadcastRun $broadcastRun,
        ContentItem $item,
        string $outcome,
        ?string $rendered = null,
        ?string $skipReason = null,
    ): void {
        $report = $broadcastRun->dry_report ?? [];
        $report[] = array_filter([
            'content_item_id' => $item->getKey(),
            'title' => $this->itemLabel($item),
            'canonical_url' => $item->canonical_url,
            'outcome' => $outcome,
            'rendered' => $rendered,
            'skip_reason' => $skipReason,
        ], fn (mixed $value): bool => $value !== null);
        $broadcastRun->dry_report = $report;
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

        // One consume per live provider fetch (cached per source for the run). On denial
        // skip the provider call and signal Out of quota to the broadcast-run boundary.
        $organization = $this->organizationForSource($source);
        $decision = $this->meter->consume($organization, QuotaMetric::SourceFetches);

        if (! $decision->allowed) {
            throw new QuotaExceededException($decision, QuotaMetric::SourceFetches);
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

        return $existing !== null && $existing->blocksRepublish();
    }

    /**
     * Preview items are not persisted, so resolve any prior delivery through
     * the item's identity on the source.
     */
    private function previewDeliveryBlocksRepublish(ContentItem $item, Broadcast $broadcast, int $destinationId): bool
    {
        $persisted = ContentItem::query()
            ->where('source_id', $item->source_id)
            ->where('identity_key', $item->identity_key)
            ->first();

        if ($persisted === null) {
            return false;
        }

        return $this->deliveryBlocksRepublish($persisted, $broadcast, $destinationId);
    }

    private function advanceLifecycle(ContentItem $item, ContentItemLifecycle $next): void
    {
        if ($this->dry) {
            return;
        }

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

    private function itemLabel(ContentItem $item): string
    {
        return $item->title ?: ($item->canonical_url ?: 'Untitled item');
    }

    private function rollUpStatus(ProjectRun $projectRun, bool $incomplete): RunStatus
    {
        if ($incomplete) {
            return RunStatus::Incomplete;
        }

        $quotaExceeded = $projectRun->broadcastRuns()
            ->where('status', RunStatus::QuotaExceeded)
            ->exists();

        return $quotaExceeded ? RunStatus::QuotaExceeded : RunStatus::Completed;
    }

    private function finishBroadcastRunOutOfQuota(
        BroadcastRun $broadcastRun,
        Broadcast $broadcast,
        Project $project,
        QuotaExceededException $exception,
    ): void {
        $broadcastRun->status = RunStatus::QuotaExceeded;
        $broadcastRun->error_detail = $exception->getMessage();
        $broadcastRun->finished_at = now();
        $broadcastRun->save();

        Log::info('quota.exceeded', [
            'organization_id' => $project->organization_id ?? $project->organization?->getKey(),
            'project_id' => $project->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'metric' => $exception->metric->value,
            'limit' => $exception->decision->limit,
            'used' => $exception->decision->used,
        ]);
    }

    /**
     * Drop or soft-restore a pending claim when delivery quota is refused so the
     * item stays undelivered without feeding consecutive_failures / auto-pause.
     */
    private function abandonDeliveryForQuota(
        ContentDelivery $delivery,
        ContentItem $item,
        QuotaDecision $decision,
    ): void {
        $hadPriorAttempts = $delivery->attempts()->exists();

        if ($hadPriorAttempts) {
            $delivery->status = DeliveryStatus::Failed;
            $delivery->failure = DeliveryFailure::Transient;
            $delivery->error_detail = $decision->message(QuotaMetric::Deliveries);
            $delivery->finished_at = now();
            $delivery->save();
        } else {
            $delivery->delete();
        }

        if ($item->lifecycle === ContentItemLifecycle::Delivering) {
            $item->lifecycle = ContentItemLifecycle::Transformed;
            $item->save();
        }
    }

    private function organizationForSource(Source $source): Organization
    {
        $source->loadMissing('project.organization');

        $organization = $source->project?->organization;

        if (! $organization instanceof Organization) {
            throw new \RuntimeException('Source is missing an organization for quota metering.');
        }

        return $organization;
    }
}
