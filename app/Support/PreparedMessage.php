<?php

namespace App\Support;

/**
 * One message ready to publish. A transformer builds this from a content item,
 * so destinations never read the item themselves.
 */
readonly class PreparedMessage
{
    /**
     * @param  list<array<array-key, mixed>>  $media
     * @param  array<string, mixed>  $fields  Every mapped destination field, as the mapping produced it (for the activity log).
     */
    public function __construct(
        public string $body,
        public ?string $link = null,
        public array $media = [],
        public array $fields = [],
    ) {}

    /**
     * The same message carrying only what the destination attaches. The mapped
     * fields are left as the mapping produced them.
     */
    public function fittedTo(DestinationMediaSupport $support): self
    {
        return new self($this->body, $this->link, $support->fit($this->media)->attached, $this->fields);
    }
}
