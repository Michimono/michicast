<?php

namespace App\Support;

/**
 * A small view model for the connection-test banner on a source or destination
 * form. Distinct from ConnectionTestResult so the controller can pass a
 * redacted message without re-wrapping the provider outcome.
 */
readonly class ConnectionTestOutcome
{
    public function __construct(
        public bool $successful,
        public string $message,
    ) {}
}
