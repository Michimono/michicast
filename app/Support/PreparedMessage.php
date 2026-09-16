<?php

namespace App\Support;

/**
 * One message ready to publish. A transformer builds this from a content item
 * (M6), so destinations never read the item themselves.
 */
readonly class PreparedMessage
{
    /**
     * @param  list<array<array-key, mixed>>  $media
     */
    public function __construct(
        public string $body,
        public ?string $link = null,
        public array $media = [],
    ) {}
}
