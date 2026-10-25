<?php

namespace App\Support;

/**
 * What a destination can do, so the UI can decide what to offer without
 * knowing which destination it is talking to.
 */
readonly class DestinationProviderCapabilities
{
    /**
     * @param  int|null  $maxTextLength  The longest message body the service accepts, when it has such a limit.
     */
    public function __construct(
        public bool $supportsConnectionTest = false,
        public bool $supportsMedia = false,
        public ?int $maxTextLength = null,
    ) {}
}
