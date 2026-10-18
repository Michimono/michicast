<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineNodeType;
use App\Exceptions\InvalidPipelineGraphException;

/**
 * The saved document behind a visual broadcast: typed nodes joined by
 * directed edges, plus the viewport the editor last showed.
 *
 * fromArray() is the single parser for stored and submitted graphs. It
 * normalizes node config to the keys each type reads and refuses anything
 * the editor cannot represent per node — unknown types, dangling or
 * incompatible edges, cycles — so a stored graph is always well-formed.
 */
readonly class PipelineGraph
{
    public const VERSION = 1;

    public const MAX_NODES = 60;

    public const MAX_EDGES = 120;

    private const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const COORDINATE_LIMIT = 100000;

    /**
     * @param  list<PipelineNode>  $nodes
     * @param  list<PipelineEdge>  $edges
     * @param  array{x: float, y: float, zoom: float}|null  $viewport
     */
    public function __construct(
        public array $nodes = [],
        public array $edges = [],
        public ?array $viewport = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidPipelineGraphException
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $nodes = [];

        $rawNodes = $data['nodes'] ?? [];
        $rawEdges = $data['edges'] ?? [];

        if (! is_array($rawNodes) || ! is_array($rawEdges)) {
            throw new InvalidPipelineGraphException('The pipeline graph is malformed.', [
                'graph' => 'The pipeline graph is malformed.',
            ]);
        }

        if (count($rawNodes) > self::MAX_NODES) {
            $errors['graph.nodes'] = 'A pipeline may have at most '.self::MAX_NODES.' nodes.';
        }

        if (count($rawEdges) > self::MAX_EDGES) {
            $errors['graph.edges'] = 'A pipeline may have at most '.self::MAX_EDGES.' connections.';
        }

        foreach (array_values($rawNodes) as $index => $raw) {
            $prefix = "graph.nodes.{$index}";

            if (! is_array($raw)) {
                $errors[$prefix] = 'Each node must be an object.';

                continue;
            }

            $id = $raw['id'] ?? null;

            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                $errors["{$prefix}.id"] = 'Each node needs an id of letters, digits, dashes or underscores.';

                continue;
            }

            if (isset($nodes[$id])) {
                $errors["{$prefix}.id"] = "Node id “{$id}” is used more than once.";

                continue;
            }

            $type = PipelineNodeType::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');

            if ($type === null) {
                $errors["{$prefix}.type"] = 'Unknown node type.';

                continue;
            }

            $x = self::coordinate($raw['x'] ?? null);
            $y = self::coordinate($raw['y'] ?? null);

            if ($x === null || $y === null) {
                $errors["{$prefix}.position"] = 'Each node needs a numeric position.';

                continue;
            }

            $config = $raw['config'] ?? [];

            if (! is_array($config)) {
                $errors["{$prefix}.config"] = 'Node settings must be an object.';

                continue;
            }

            $nodes[$id] = new PipelineNode($id, $type, $x, $y, self::normalizeConfig($type, $config));
        }

        $edges = [];
        $seenPairs = [];

        foreach (array_values($rawEdges) as $index => $raw) {
            $prefix = "graph.edges.{$index}";

            if (! is_array($raw)) {
                $errors[$prefix] = 'Each connection must be an object.';

                continue;
            }

            $id = $raw['id'] ?? null;
            $from = $raw['from'] ?? null;
            $to = $raw['to'] ?? null;

            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                $errors["{$prefix}.id"] = 'Each connection needs an id of letters, digits, dashes or underscores.';

                continue;
            }

            if (isset($edges[$id])) {
                $errors["{$prefix}.id"] = "Connection id “{$id}” is used more than once.";

                continue;
            }

            if (! is_string($from) || ! is_string($to) || ! isset($nodes[$from]) || ! isset($nodes[$to])) {
                $errors["{$prefix}.nodes"] = 'A connection must join two existing nodes.';

                continue;
            }

            if ($from === $to) {
                $errors["{$prefix}.nodes"] = 'A node cannot connect to itself.';

                continue;
            }

            if (isset($seenPairs[$from.'>'.$to])) {
                $errors["{$prefix}.nodes"] = 'These two nodes are already connected.';

                continue;
            }

            $fromType = $nodes[$from]->type;
            $toType = $nodes[$to]->type;

            if (! $fromType->hasOutput() || ! $toType->hasInput() || ! $toType->accepts($fromType)) {
                $errors["{$prefix}.nodes"] = sprintf(
                    'A %s cannot feed a %s.',
                    strtolower($fromType->label()),
                    strtolower($toType->label()),
                );

                continue;
            }

            $seenPairs[$from.'>'.$to] = true;
            $edges[$id] = new PipelineEdge($id, $from, $to);
        }

        if ($errors === [] && self::hasCycle($nodes, $edges)) {
            $errors['graph.edges'] = 'The pipeline loops back on itself. Content must flow from sources to destinations.';
        }

        if ($errors !== []) {
            throw new InvalidPipelineGraphException('The pipeline graph could not be saved.', $errors);
        }

        return new self(
            nodes: array_values($nodes),
            edges: array_values($edges),
            viewport: self::viewport($data['viewport'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'version' => self::VERSION,
            'nodes' => array_map(static fn (PipelineNode $node): array => $node->toArray(), $this->nodes),
            'edges' => array_map(static fn (PipelineEdge $edge): array => $edge->toArray(), $this->edges),
        ];

        if ($this->viewport !== null) {
            $payload['viewport'] = $this->viewport;
        }

        return $payload;
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    public function node(string $id): ?PipelineNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<PipelineNode>
     */
    public function nodesOfType(PipelineNodeType $type): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (PipelineNode $node): bool => $node->type === $type,
        ));
    }

    /**
     * Downstream node ids keyed by upstream node id.
     *
     * @return array<string, list<string>>
     */
    public function adjacency(): array
    {
        $adjacency = [];

        foreach ($this->nodes as $node) {
            $adjacency[$node->id] = [];
        }

        foreach ($this->edges as $edge) {
            $adjacency[$edge->from][] = $edge->to;
        }

        return $adjacency;
    }

    /**
     * Node ids referenced by at least one edge.
     *
     * @return array<string, true>
     */
    public function connectedNodeIds(): array
    {
        $connected = [];

        foreach ($this->edges as $edge) {
            $connected[$edge->from] = true;
            $connected[$edge->to] = true;
        }

        return $connected;
    }

    /**
     * Keep only the keys the node type reads, with scalar coercion the
     * compiler can rely on. Unknown keys are dropped rather than stored.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function normalizeConfig(PipelineNodeType $type, array $config): array
    {
        $normalized = [];

        $label = $config['label'] ?? null;

        if (is_string($label) && trim($label) !== '') {
            $normalized['label'] = mb_substr(trim($label), 0, 80);
        }

        switch ($type) {
            case PipelineNodeType::Source:
                $normalized['source_id'] = is_numeric($config['source_id'] ?? null) ? (int) $config['source_id'] : null;
                break;

            case PipelineNodeType::Destination:
                $normalized['destination_id'] = is_numeric($config['destination_id'] ?? null) ? (int) $config['destination_id'] : null;
                break;

            case PipelineNodeType::Mapping:
                $mapping = self::normalizeMapping($config['mapping'] ?? null);

                if ($mapping !== null) {
                    $normalized['mapping'] = $mapping;
                }
                break;

            case PipelineNodeType::Filter:
                $normalized['rules'] = self::normalizeRules($config['rules'] ?? [], ['type', 'field', 'value', 'hours']);
                break;

            case PipelineNodeType::Transform:
                $template = $config['template'] ?? '';

                if (is_string($template) && trim($template) !== '') {
                    $normalized['template'] = mb_substr($template, 0, 5000);
                }

                $normalized['rules'] = self::normalizeRules($config['rules'] ?? [], ['type', 'field', 'value', 'length', 'pattern', 'replacement']);
                break;
        }

        return $normalized;
    }

    /**
     * A mapping node is a list of Set field steps: which destination field
     * receives which source template. Other operations are dropped, and a
     * mapping with no steps is omitted.
     *
     * @return array<string, mixed>|null
     */
    private static function normalizeMapping(mixed $mapping): ?array
    {
        if (! is_array($mapping) || ! is_array($mapping['steps'] ?? null)) {
            return null;
        }

        $steps = [];

        foreach (array_values($mapping['steps']) as $index => $step) {
            if (count($steps) >= 20 || ! is_array($step)) {
                continue;
            }

            $operation = $step['operation'] ?? $step['op'] ?? '';

            if ($operation !== 'set_field') {
                continue;
            }

            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $field = $config['field'] ?? '';
            $value = $config['value'] ?? '';

            if (! is_string($field) || trim($field) === '' || ! is_string($value)) {
                continue;
            }

            $id = $step['id'] ?? null;
            $id = is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1
                ? $id
                : 'step-'.$index;

            $steps[] = [
                'id' => $id,
                'operation' => 'set_field',
                'order' => count($steps),
                'config' => [
                    'field' => mb_substr(trim($field), 0, 80),
                    'value' => mb_substr($value, 0, 5000),
                ],
            ];
        }

        if ($steps === []) {
            return null;
        }

        $normalized = [
            'version' => 1,
            'steps' => $steps,
        ];

        foreach (['source_provider', 'destination_provider'] as $key) {
            $value = $mapping[$key] ?? null;

            if (is_string($value) && preg_match('/^[a-z0-9_]{1,40}$/', $value) === 1) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function normalizeRules(mixed $rules, array $keys): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice(array_values($rules), 0, 20) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $entry = [];

            foreach ($keys as $key) {
                if (! array_key_exists($key, $rule) || $rule[$key] === null || $rule[$key] === '') {
                    continue;
                }

                $value = $rule[$key];

                if (in_array($key, ['hours', 'length'], true)) {
                    $entry[$key] = is_numeric($value) ? (int) $value : $value;
                } elseif (is_scalar($value)) {
                    $entry[$key] = mb_substr((string) $value, 0, 1000);
                }
            }

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private static function coordinate(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number) || abs($number) > self::COORDINATE_LIMIT) {
            return null;
        }

        return round($number, 2);
    }

    /**
     * @return array{x: float, y: float, zoom: float}|null
     */
    private static function viewport(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $x = self::coordinate($raw['x'] ?? null);
        $y = self::coordinate($raw['y'] ?? null);
        $zoom = is_numeric($raw['zoom'] ?? null) ? (float) $raw['zoom'] : null;

        if ($x === null || $y === null || $zoom === null || $zoom < 0.1 || $zoom > 4) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'zoom' => round($zoom, 3)];
    }

    /**
     * @param  array<string, PipelineNode>  $nodes
     * @param  array<string, PipelineEdge>  $edges
     */
    private static function hasCycle(array $nodes, array $edges): bool
    {
        $indegree = array_fill_keys(array_keys($nodes), 0);
        $adjacency = array_fill_keys(array_keys($nodes), []);

        foreach ($edges as $edge) {
            $adjacency[$edge->from][] = $edge->to;
            $indegree[$edge->to]++;
        }

        $queue = array_keys(array_filter($indegree, static fn (int $degree): bool => $degree === 0));
        $visited = 0;

        while ($queue !== []) {
            $current = array_shift($queue);
            $visited++;

            foreach ($adjacency[$current] as $next) {
                if (--$indegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return $visited !== count($nodes);
    }
}
