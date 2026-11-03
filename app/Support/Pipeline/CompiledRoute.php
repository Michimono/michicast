<?php

namespace App\Support\Pipeline;

/**
 * One source → destination path of the graph, flattened into what a
 * broadcasts row stores: the filter rules met along the way (AND) and the
 * single transform the path carries, if any, and the destination's mapping.
 */
readonly class CompiledRoute
{
    /**
     * @param  list<string>  $nodeIds  Every node on the path, in flow order.
     * @param  array{rules: list<array<string, mixed>>}|null  $filter
     * @param  array{template?: string, rules?: list<array<string, mixed>>}|null  $transform
     * @param  array{version?: int, steps?: list<array<string, mixed>>, source_provider?: string, destination_provider?: string}|null  $mapping
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
        public ?array $mapping = null,
        public int $order = 0,
    ) {}

    /**
     * The source and destination records this route joins. Several routes may
     * share it when the canvas has parallel paths between the same pair.
     */
    public function key(): string
    {
        return $this->sourceId.':'.$this->destinationId;
    }

    /**
     * Stable identity of this exact canvas path, so each path keeps its own
     * broadcasts row (and delivery history) while its nodes are edited.
     */
    public function pathKey(): string
    {
        return hash('sha256', implode('>', $this->nodeIds));
    }

    /**
     * Paths leaving the same source node for the same destination node form
     * one group: an item is sent once per group, by the first path (lowest
     * order) that lets it through. A second source node is its own group.
     */
    public function groupKey(): string
    {
        return hash('sha256', $this->sourceNodeId.'>'.$this->destinationNodeId);
    }

    /**
     * @return array{route_key: string, route_group: string, route_order: int}
     */
    public function rowKeys(): array
    {
        return [
            'route_key' => $this->pathKey(),
            'route_group' => $this->groupKey(),
            'route_order' => $this->order,
        ];
    }
}
