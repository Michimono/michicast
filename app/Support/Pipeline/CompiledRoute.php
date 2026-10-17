<?php

namespace App\Support\Pipeline;

/**
 * One source → destination path of the graph, flattened into what a
 * broadcasts row stores: the filter rules met along the way (AND) and the
 * single transform the path carries, if any.
 */
readonly class CompiledRoute
{
    /**
     * @param  list<string>  $nodeIds  Every node on the path, in flow order.
     * @param  array{rules: list<array<string, mixed>>}|null  $filter
     * @param  array{template?: string, rules?: list<array<string, mixed>>}|null  $transform
     */
    public function __construct(
        public int $sourceId,
        public int $destinationId,
        public string $sourceNodeId,
        public string $destinationNodeId,
        public string $name,
        public array $nodeIds,
        public ?array $filter,
        public ?array $transform,
    ) {}

    public function key(): string
    {
        return $this->sourceId.':'.$this->destinationId;
    }
}
