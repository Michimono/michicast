<?php

namespace App\Support\Pipeline;

/**
 * A directed connection between two nodes: content flows from → to.
 */
readonly class PipelineEdge
{
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
    ) {}

    /**
     * @return array{id: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
