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
        public ?int $retryAfterSeconds = null,
        public ?string $publishedMessage = null,
        /** @var array<string, mixed>|null */
        public ?array $publishedPayload = null,
    ) {}

    /**
     * @param  string|null  $externalReference  The destination's own id for the published message, when it gives one.
     * @param  string|null  $publishedMessage  The message as published, kept on the delivery when the destination is the activity log itself.
     * @param  array<string, mixed>|null  $publishedPayload  The internal object that was delivered, kept as JSON for analysis.
     */
    public static function success(?string $externalReference = null, ?string $publishedMessage = null, ?array $publishedPayload = null): self
    {
        return new self(true, $externalReference, publishedMessage: $publishedMessage, publishedPayload: $publishedPayload);
    }

    /**
     * The destination will reject this message however often it is sent.
     */
    public static function permanentFailure(string $reason): self
    {
        return new self(false, null, $reason, DeliveryFailure::Permanent);
    }

    /**
     * The attempt failed for a reason that may pass later, such as a timeout
     * or a rate limit. When the destination says how long to wait, that hint
     * is carried as retryAfterSeconds.
     */
    public static function transientFailure(string $reason, ?int $retryAfterSeconds = null): self
    {
        return new self(false, null, $reason, DeliveryFailure::Transient, $retryAfterSeconds);
    }

    public function isRetryable(): bool
    {
        return $this->failure === DeliveryFailure::Transient;
    }
}
