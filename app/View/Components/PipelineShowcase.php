<?php

namespace App\View\Components;

use App\Enums\PipelineNodeType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * The landing page's product figure: one example pipeline, drawn the way the
 * editor draws one. The example is assembled from the providers this instance
 * registers, so the figure never shows a source or destination that cannot be
 * built here. It is laid out twice, left to right for wide screens and top to
 * bottom for phones, because a drawing that only scales becomes unreadable.
 *
 * @phpstan-type Node array{kind: string, category: string, label: string, title: string, meta: string, x: int, y: int, selected: bool}
 * @phpstan-type Edge array{path: string, arrow: string, category: string, handle: array{x: float, y: float}, duration: float, delay: float}
 * @phpstan-type Layout array{width: int, height: int, node: array{width: int, height: int}, nodes: list<Node>, edges: list<Edge>}
 */
class PipelineShowcase extends Component
{
    /**
     * Example names for a source of each known type. A type that appears more
     * than once takes the next name; an unknown type falls back to its label.
     */
    private const SOURCE_EXAMPLES = [
        'rss' => ['Advisories', 'Release notes', 'Project blog'],
        'reddit' => ['r/netsec', 'r/devops', 'r/sysadmin'],
        'twitter' => ['@vendor_status', '@release_feed', '@project_news'],
        'web_page' => ['Status page', 'Changelog', 'Notice board'],
    ];

    /** The source types the three example slots prefer, when registered. */
    private const SOURCE_SLOTS = ['rss', 'reddit', 'web_page'];

    private const DESTINATION_EXAMPLES = ['Security team', 'Ops channel'];

    private const ARROW = 8;

    /** @var Layout */
    public array $wide;

    /** @var Layout */
    public array $tall;

    /** What the figure shows, for people who cannot see it. */
    public string $summary;

    /** The destination type the example message is delivered through. */
    public string $channel;

    /** The example destination that receives the message. */
    public string $recipient;

    /**
     * @param  array<string, string>  $sources  Source type names keyed by type.
     * @param  array<string, string>  $destinations  Destination type names keyed by type.
     */
    public function __construct(array $sources = [], array $destinations = [])
    {
        $from = $this->exampleSources($sources);
        $to = $this->exampleDestinations($destinations);

        $this->channel = $to[0]['meta'] !== '' ? $to[0]['meta'] : PipelineNodeType::Destination->label();
        $this->recipient = $to[0]['title'];
        $this->wide = $this->wideLayout($from, $to);
        $this->tall = $this->tallLayout($from, $to);

        $this->summary = sprintf(
            'An example pipeline in the visual editor. Three sources (%s) pass through a filter, a transform and a mapping, and are delivered to two destinations (%s).',
            implode(', ', array_column($from, 'title')),
            implode(' and ', array_column($to, 'title')),
        );
    }

    public function render(): View
    {
        return view('components.pipeline-showcase');
    }

    /**
     * Three example sources. Each slot takes its preferred type when the
     * instance registers it, then any type not shown yet, then repeats.
     *
     * @param  array<string, string>  $sources
     * @return list<array{title: string, meta: string}>
     */
    private function exampleSources(array $sources): array
    {
        $types = array_keys($sources);
        $unused = array_values(array_diff($types, self::SOURCE_SLOTS));
        $seen = [];
        $examples = [];

        foreach (self::SOURCE_SLOTS as $index => $preferred) {
            $type = match (true) {
                isset($sources[$preferred]) => $preferred,
                $unused !== [] => array_shift($unused),
                $types !== [] => $types[$index % count($types)],
                default => null,
            };

            if ($type === null) {
                $examples[] = ['title' => PipelineNodeType::Source->label(), 'meta' => ''];

                continue;
            }

            $position = $seen[$type] ?? 0;
            $seen[$type] = $position + 1;

            $examples[] = [
                'title' => self::SOURCE_EXAMPLES[$type][$position] ?? $sources[$type],
                'meta' => $sources[$type],
            ];
        }

        return $examples;
    }

    /**
     * Two example destinations, of two types when the instance has two.
     *
     * @param  array<string, string>  $destinations
     * @return list<array{title: string, meta: string}>
     */
    private function exampleDestinations(array $destinations): array
    {
        $names = array_values($destinations);
        $examples = [];

        foreach (self::DESTINATION_EXAMPLES as $index => $title) {
            $examples[] = [
                'title' => $title,
                'meta' => $names === [] ? '' : $names[$index % count($names)],
            ];
        }

        return $examples;
    }

    /**
     * Left to right: two sources through a filter and a mapping to both
     * destinations, and a third source through a transform to one of them.
     *
     * @param  list<array{title: string, meta: string}>  $from
     * @param  list<array{title: string, meta: string}>  $to
     * @return Layout
     */
    private function wideLayout(array $from, array $to): array
    {
        $size = ['width' => 184, 'height' => 60];

        $nodes = [
            'first' => $this->node(PipelineNodeType::Source, $from[0], 20, 28),
            'second' => $this->node(PipelineNodeType::Source, $from[1], 20, 128),
            'third' => $this->node(PipelineNodeType::Source, $from[2], 20, 276),
            'filter' => $this->node(PipelineNodeType::Filter, $this->filter(), 272, 78, selected: true),
            'transform' => $this->node(PipelineNodeType::Transform, $this->transform(), 272, 276),
            'mapping' => $this->node(PipelineNodeType::Mapping, $this->mapping(), 524, 78),
            'primary' => $this->node(PipelineNodeType::Destination, $to[0], 776, 28),
            'secondary' => $this->node(PipelineNodeType::Destination, $to[1], 776, 148),
        ];

        $edges = [
            ['first', 'filter', 2.6, 0.0],
            ['second', 'filter', 2.6, 1.3],
            ['filter', 'mapping', 2.2, 0.7],
            ['mapping', 'primary', 2.4, 1.5],
            ['mapping', 'secondary', 2.4, 0.3],
            ['third', 'transform', 2.4, 0.9],
            ['transform', 'secondary', 3.6, 1.9],
        ];

        return [
            'width' => 980,
            'height' => 364,
            'node' => $size,
            'nodes' => array_values($nodes),
            'edges' => array_map(
                fn (array $edge): array => $this->edgeAcross($nodes[$edge[0]], $nodes[$edge[1]], $size, $edge[2], $edge[3]),
                $edges,
            ),
        ];
    }

    /**
     * Top to bottom for narrow screens: the same idea with fewer nodes, so
     * every label stays readable at phone width.
     *
     * @param  list<array{title: string, meta: string}>  $from
     * @param  list<array{title: string, meta: string}>  $to
     * @return Layout
     */
    private function tallLayout(array $from, array $to): array
    {
        $size = ['width' => 158, 'height' => 54];

        $nodes = [
            'first' => $this->node(PipelineNodeType::Source, $from[0], 8, 12),
            'second' => $this->node(PipelineNodeType::Source, $from[1], 174, 12),
            'filter' => $this->node(PipelineNodeType::Filter, $this->filter(), 91, 112, selected: true),
            'mapping' => $this->node(PipelineNodeType::Mapping, $this->mapping(), 91, 212),
            'primary' => $this->node(PipelineNodeType::Destination, $to[0], 8, 312),
            'secondary' => $this->node(PipelineNodeType::Destination, $to[1], 174, 312),
        ];

        $edges = [
            ['first', 'filter', 2.4, 0.0],
            ['second', 'filter', 2.4, 1.2],
            ['filter', 'mapping', 2.0, 0.6],
            ['mapping', 'primary', 2.4, 1.4],
            ['mapping', 'secondary', 2.4, 0.2],
        ];

        return [
            'width' => 340,
            'height' => 378,
            'node' => $size,
            'nodes' => array_values($nodes),
            'edges' => array_map(
                fn (array $edge): array => $this->edgeDown($nodes[$edge[0]], $nodes[$edge[1]], $size, $edge[2], $edge[3]),
                $edges,
            ),
        ];
    }

    /**
     * @return array{title: string, meta: string}
     */
    private function filter(): array
    {
        return ['title' => 'Critical only', 'meta' => 'contains: critical'];
    }

    /**
     * @return array{title: string, meta: string}
     */
    private function transform(): array
    {
        return ['title' => 'Tidy the text', 'meta' => 'Strip HTML, truncate'];
    }

    /**
     * @return array{title: string, meta: string}
     */
    private function mapping(): array
    {
        return ['title' => 'Alert message', 'meta' => 'Title, text, link'];
    }

    /**
     * @param  array{title: string, meta: string}  $content
     * @return Node
     */
    private function node(PipelineNodeType $type, array $content, int $x, int $y, bool $selected = false): array
    {
        return [
            'kind' => $type->value,
            'category' => $type->category(),
            'label' => $type->label(),
            // SVG text does not truncate itself, and a provider label can be any length.
            'title' => Str::limit($content['title'], 16, '...'),
            'meta' => Str::limit($content['meta'], 20, '...'),
            'x' => $x,
            'y' => $y,
            'selected' => $selected,
        ];
    }

    /**
     * A connection leaving the right side of one node for the left of another.
     *
     * @param  Node  $from
     * @param  Node  $to
     * @param  array{width: int, height: int}  $size
     * @return Edge
     */
    private function edgeAcross(array $from, array $to, array $size, float $duration, float $delay): array
    {
        $startX = $from['x'] + $size['width'];
        $startY = $from['y'] + $size['height'] / 2;
        $tipX = $to['x'];
        $endX = $tipX - self::ARROW;
        $endY = $to['y'] + $size['height'] / 2;
        $pull = max(30, ($endX - $startX) / 2);

        return [
            'path' => sprintf(
                'M%s %s C%s %s %s %s %s %s',
                $startX, $startY, $startX + $pull, $startY, $endX - $pull, $endY, $endX, $endY,
            ),
            'arrow' => sprintf('M%s %s l-%s -4.5 v9 z', $tipX, $endY, self::ARROW + 1),
            'category' => $from['category'],
            'handle' => ['x' => $startX, 'y' => $startY],
            'duration' => $duration,
            'delay' => $delay,
        ];
    }

    /**
     * A connection leaving the bottom of one node for the top of another.
     *
     * @param  Node  $from
     * @param  Node  $to
     * @param  array{width: int, height: int}  $size
     * @return Edge
     */
    private function edgeDown(array $from, array $to, array $size, float $duration, float $delay): array
    {
        $startX = $from['x'] + $size['width'] / 2;
        $startY = $from['y'] + $size['height'];
        $endX = $to['x'] + $size['width'] / 2;
        $tipY = $to['y'];
        $endY = $tipY - self::ARROW;
        $pull = max(16, ($endY - $startY) / 2);

        return [
            'path' => sprintf(
                'M%s %s C%s %s %s %s %s %s',
                $startX, $startY, $startX, $startY + $pull, $endX, $endY - $pull, $endX, $endY,
            ),
            'arrow' => sprintf('M%s %s l-4.5 -%s h9 z', $endX, $tipY, self::ARROW + 1),
            'category' => $from['category'],
            'handle' => ['x' => $startX, 'y' => $startY],
            'duration' => $duration,
            'delay' => $delay,
        ];
    }
}
