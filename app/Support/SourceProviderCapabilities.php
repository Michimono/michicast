<?php

namespace App\Support;

/**
 * What a provider can do, so the UI can decide what to offer without knowing
 * which provider it is talking to.
 */
readonly class SourceProviderCapabilities
{
    public function __construct(
        public bool $supportsConnectionTest = false,
        public bool $offeredInForms = true,
    ) {}
}
