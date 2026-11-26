<?php

namespace App\Support;

/**
 * What a destination can do, so the UI can decide what to offer without
 * knowing which destination it is talking to.
 */
readonly class DestinationProviderCapabilities
{
    /**
     * What the destination attaches to a message. Text only unless declared.
     */
    public DestinationMediaSupport $media;

    /**
     * @param  int|null  $maxTextLength  The longest message body the service accepts, when it has such a limit.
     */
    public function __construct(
        public bool $supportsConnectionTest = false,
        ?DestinationMediaSupport $media = null,
        public ?int $maxTextLength = null,
    ) {
        $this->media = $media ?? DestinationMediaSupport::none();
    }

    public function supportsMedia(): bool
    {
        return $this->media->acceptsAny();
    }
}
