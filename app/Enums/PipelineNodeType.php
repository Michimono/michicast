<?php

namespace App\Enums;

/**
 * The closed set of node types a pipeline graph may contain. Each type knows
 * which upstream types may connect into it, which is what keeps the graph
 * compilable into source → destination routes.
 */
enum PipelineNodeType: string
{
    case Source = 'source';
    case Filter = 'filter';
    case Transform = 'transform';
    case Mapping = 'mapping';
    case Destination = 'destination';

    public function label(): string
    {
        return match ($this) {
            self::Source => 'Source',
            self::Filter => 'Filter',
            self::Transform => 'Transform',
            self::Mapping => 'Mapping',
            self::Destination => 'Destination',
        };
    }

    /**
     * The palette group the node is shown under: sources, the middle
     * steps (filters, transforms, mappings), destinations.
     */
    public function category(): string
    {
        return match ($this) {
            self::Source => 'source',
            self::Filter, self::Transform, self::Mapping => 'transform',
            self::Destination => 'destination',
        };
    }

    public function hasInput(): bool
    {
        return $this !== self::Source;
    }

    public function hasOutput(): bool
    {
        return $this !== self::Destination;
    }

    /**
     * Whether an edge from $upstream may end on this node. Filters check the
     * item, so they sit before a transform or a mapping. A transform changes
     * one field and may sit on either side of a mapping. The mapping is what
     * writes the message.
     */
    public function accepts(self $upstream): bool
    {
        return match ($this) {
            self::Source => false,
            self::Filter => in_array($upstream, [self::Source, self::Filter], true),
            self::Mapping => in_array($upstream, [self::Source, self::Filter, self::Transform], true),
            self::Transform => in_array($upstream, [self::Source, self::Filter, self::Mapping], true),
            self::Destination => $upstream !== self::Destination,
        };
    }

    /**
     * @return list<string>
     */
    public function acceptedUpstreamTypes(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $this->accepts($type)),
        ));
    }
}
