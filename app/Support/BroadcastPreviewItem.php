<?php

namespace App\Support;

/**
 * One item shown in a broadcast preview: source input beside the rendered
 * destination body, or the reason it would be skipped.
 */
readonly class BroadcastPreviewItem
{
    public function __construct(
        public string $title,
        public ?string $body,
        public ?string $canonicalUrl,
        public string $outcome,
        public ?string $rendered = null,
        public ?string $skipReason = null,
    ) {}

    public function wouldPublish(): bool
    {
        return $this->outcome === 'publish';
    }
}
