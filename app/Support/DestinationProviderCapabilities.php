<?php

namespace App\Support;

/**
 * What a destination can do, so the UI can decide what to offer without
 * knowing which destination it is talking to.
 */
readonly class DestinationProviderCapabilities
{
    public function __construct(
        public bool $supportsConnectionTest = false,
        public bool $supportsMedia = false,
    ) {}
}
