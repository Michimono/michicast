<?php

namespace App\Support;

use App\Enums\DeliveryFailure;

/**
 * What a destination reports back about one publish attempt. A rejected
 * message is a result, not an exception, and the execution engine decides
 * whether to retry from the failure kind rather than from the reason text.
 */
readonly class DeliveryResult
{
    private function __construct(
        public bool $successful,
        public ?string $externalReference = null,
        public ?string $failureReason = null,
        public ?DeliveryFailure $failure = null,
    ) {}

    /**
     * @param  string|null  $externalReference  The destination's own id for the published message, when it gives one.
     */
    public static function success(?string $externalReference = null): self
    {
        return new self(true, $externalReference);
    }

    /**
     * The destination will reject this message however often it is sent.
     */
    public static function permanentFailure(string $reason): self
    {
        return new self(false, null, $reason, DeliveryFailure::Permanent);
    }

    /**
     * The attempt failed for a reason that may pass later, such as a timeout.
     */
    public static function transientFailure(string $reason): self
    {
        return new self(false, null, $reason, DeliveryFailure::Transient);
    }

    public function isRetryable(): bool
    {
        return $this->failure === DeliveryFailure::Transient;
    }
}
