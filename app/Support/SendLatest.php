<?php

namespace App\Support;

use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ProjectRun;

/**
 * Clears delivery state for the latest received source item and publishes
 * only that item now, bypassing backfill and prior-delivery blockers.
 * Does not walk the rest of the feed or bulk-send history.
 */
class SendLatest
{
    public function __construct(
        private AllowLatestResend $allowLatestResend,
        private ExecutingProjectRunner $runner,
    ) {}

    /**
     * @return array{item: ContentItem, projectRun: ProjectRun, delivered: int, skipped: int}|null
     */
    public function handle(Project $project): ?array
    {
        $prepared = $this->allowLatestResend->handle($project);

        if ($prepared === null) {
            return null;
        }

        /** @var ContentItem $item */
        $item = $prepared['item']->fresh() ?? $prepared['item'];

        $projectRun = $this->runner->publishSingleItem($project, $item, bypassBackfill: true);

        return [
            'item' => $item->fresh() ?? $item,
            'projectRun' => $projectRun,
            'delivered' => (int) $projectRun->items_delivered,
            'skipped' => (int) $projectRun->items_skipped,
        ];
    }
}
