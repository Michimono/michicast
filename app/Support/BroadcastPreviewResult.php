<?php

namespace App\Support;

/**
 * @param  list<BroadcastPreviewItem>  $items
 */
readonly class BroadcastPreviewResult
{
    /**
     * @param  list<BroadcastPreviewItem>  $items
     */
    public function __construct(
        public array $items,
        public int $found,
        public int $wouldPublish,
        public int $wouldSkip,
    ) {}
}
