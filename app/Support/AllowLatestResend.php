<?php

namespace App\Support;

use App\Enums\ContentItemLifecycle;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Project;

/**
 * Clears delivery and skip state for the latest received source item so a later
 * Run now can publish it again. Does not publish on its own. Run now still
 * applies backfill and filters; use SendLatest when those should be bypassed
 * for a one-item correction publish.
 */
class AllowLatestResend
{
    /**
     * @return array{item: ContentItem, cleared: int}|null
     */
    public function handle(Project $project): ?array
    {
        $item = ContentItem::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->first();

        if ($item === null) {
            return null;
        }

        $broadcastIds = $project->broadcasts()->pluck('id');

        $cleared = 0;

        if ($broadcastIds->isNotEmpty()) {
            $cleared = ContentDelivery::query()
                ->where('content_item_id', $item->getKey())
                ->whereIn('broadcast_id', $broadcastIds)
                ->delete();
        }

        if (in_array($item->lifecycle, [
            ContentItemLifecycle::Delivered,
            ContentItemLifecycle::Skipped,
            ContentItemLifecycle::Failed,
            ContentItemLifecycle::Delivering,
            ContentItemLifecycle::Transformed,
            ContentItemLifecycle::Eligible,
        ], true)) {
            $item->lifecycle = ContentItemLifecycle::Normalized;
            $item->save();
        }

        return [
            'item' => $item,
            'cleared' => $cleared,
        ];
    }
}
