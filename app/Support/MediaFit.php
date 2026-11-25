<?php

namespace App\Support;

/**
 * The outcome of fitting a message's media to one destination: what goes out
 * with the message, and how many mapped entries stay behind.
 *
 * @phpstan-import-type MediaEntry from MediaList
 */
readonly class MediaFit
{
    /**
     * @param  list<MediaEntry>  $attached
     */
    public function __construct(
        public array $attached,
        public int $leftOff,
        public DestinationMediaSupport $support,
    ) {}

    /**
     * A literal line for previews when something is left off; null when
     * everything mapped is attached.
     */
    public function note(): ?string
    {
        if ($this->leftOff === 0) {
            return null;
        }

        if (! $this->support->acceptsAny()) {
            return 'Not attached. This destination sends text only.';
        }

        return $this->leftOff.' left off. This destination takes '.$this->support->describe().' per message.';
    }

    /**
     * @return array{attached: list<MediaEntry>, left_off: int, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'attached' => $this->attached,
            'left_off' => $this->leftOff,
            'note' => $this->note(),
        ];
    }
}
