<?php

namespace App\Support;

/**
 * The outcome of a connection test. A refused connection is a result, not an
 * exception, so the UI can show the reason.
 */
readonly class ConnectionTestResult
{
    private function __construct(
        public bool $successful,
        public string $message,
    ) {}

    public static function success(string $message = 'The connection succeeded.'): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }

    public static function unsupported(): self
    {
        return new self(false, 'This provider does not support connection testing.');
    }
}
