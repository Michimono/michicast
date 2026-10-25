<?php

namespace App\Support\Pipeline;

use App\Enums\BroadcastFilterRuleType;
use App\Enums\BroadcastTransformRuleType;
use App\Enums\PipelineNodeType;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;
use App\Support\BroadcastFilters\BoundedRegex;
use App\Support\BroadcastTransforms\TemplatePlaceholders;
use Illuminate\Support\Collection;

/**
 * Turns a well-formed graph into the routes the runner already understands.
 *
 * Every path from a source node to a destination node becomes one route:
 * the filters met along the way are ANDed (the evaluator already does that
 * with a rule list) and the single transform on the path becomes the route's
 * template, kept for a transform that still stores one. Field rules on a
 * transform and Set field steps on a mapping are written in path order, so
 * a transform can change a field before or after the mapping. One source
 * record reaches a destination through one broadcast
 * row, so the item is delivered once. Extra nodes for that same pair stay
 * on the route (their connections stay live) and are reported as a warning.
 * Two different paths out of the same node are an error: their filters
 * would disagree, so neither compiles. Nodes whose settings are incomplete keep every
 * path through them from compiling, so a half-built filter never lets
 * everything through.
 */
class PipelineGraphCompiler
{
    private const MAX_PATHS = 200;

    /**
     * Text fields a visual filter or transform rule may name. Published time
     * is separate: those rules take a number of hours.
     */
    private const TEXT_FIELDS = ['title', 'body', 'author', 'link'];

    public function compile(PipelineGraph $graph, Project $project): PipelineCompilation
    {
        $sources = $project->sources()->get()->keyBy(fn (Source $source): int => (int) $source->getKey());
        $destinations = $project->destinations()->get()->keyBy(fn (Destination $destination): int => (int) $destination->getKey());

        return $this->compileWith($graph, $sources, $destinations);
    }

    /**
     * @param  Collection<int, Source>  $sources  The project's sources keyed by id, archived ones included.
     * @param  Collection<int, Destination>  $destinations  The project's destinations keyed by id, archived ones included.
     */
    public function compileWith(PipelineGraph $graph, Collection $sources, Collection $destinations): PipelineCompilation
    {
        $issues = [];
        $broken = [];

        foreach ($graph->nodes as $node) {
            $problem = $this->nodeProblem($node, $sources, $destinations);

            if ($problem !== null) {
                $issues[] = PipelineIssue::error($problem, $node->id);
                $broken[$node->id] = true;
            }
        }

        $connected = $graph->connectedNodeIds();

        foreach ($graph->nodes as $node) {
            if (! isset($connected[$node->id]) && ! isset($broken[$node->id])) {
                $issues[] = PipelineIssue::warning($this->unconnectedMessage($node), $node->id);
            }
        }

        $adjacency = $graph->adjacency();
        $nodesById = [];

        foreach ($graph->nodes as $node) {
            $nodesById[$node->id] = $node;
        }

        /** @var array<string, list<list<string>>> $pathsByPair */
        $pathsByPair = [];
        $onCompletePath = [];
        $pathCount = 0;

        foreach ($graph->nodesOfType(PipelineNodeType::Source) as $source) {
            if (isset($broken[$source->id])) {
                continue;
            }

            $stack = [[$source->id]];

            while ($stack !== []) {
                $path = array_pop($stack);
                $last = $path[array_key_last($path)];

                foreach ($adjacency[$last] as $next) {
                    if (isset($broken[$next])) {
                        continue;
                    }

                    $extended = [...$path, $next];

                    if ($nodesById[$next]->type === PipelineNodeType::Destination) {
                        if (++$pathCount > self::MAX_PATHS) {
                            break 3;
                        }

                        $pair = $source->id.'>'.$next;
                        $pathsByPair[$pair][] = $extended;

                        foreach ($extended as $id) {
                            $onCompletePath[$id] = true;
                        }

                        continue;
                    }

                    $stack[] = $extended;
                }
            }
        }

        $routes = [];
        $byRecordPair = [];

        foreach ($pathsByPair as $paths) {
            $first = $paths[0];
            $sourceNode = $nodesById[$first[0]];
            $destinationNode = $nodesById[$first[array_key_last($first)]];

            if (count($paths) > 1) {
                $issues[] = PipelineIssue::error(
                    sprintf(
                        '%s reaches this destination through %d paths. Keep one so each item is delivered once.',
                        $this->sourceName($sourceNode, $sources),
                        count($paths),
                    ),
                    $destinationNode->id,
                );

                continue;
            }

            $route = $this->routeFor($first, $nodesById, $sources, $destinations);
            $recordPair = $route->key();

            if (isset($byRecordPair[$recordPair])) {
                $issues[] = PipelineIssue::warning(
                    sprintf(
                        '%s already reaches %s through another node. Both connections stay live, and each item is delivered once.',
                        $this->sourceName($sourceNode, $sources),
                        $this->destinationName($destinationNode, $destinations),
                    ),
                    $sourceNode->id,
                );
            } else {
                $byRecordPair[$recordPair] = true;
            }

            $routes[] = $route;
        }

        foreach ($graph->nodes as $node) {
            if (isset($connected[$node->id]) && ! isset($onCompletePath[$node->id]) && ! isset($broken[$node->id])) {
                $issues[] = PipelineIssue::warning($this->incompletePathMessage($node), $node->id);
            }
        }

        return new PipelineCompilation($routes, $issues);
    }

    /**
     * @param  list<string>  $path
     * @param  array<string, PipelineNode>  $nodesById
     * @param  Collection<int, Source>  $sources
     * @param  Collection<int, Destination>  $destinations
     */
    private function routeFor(array $path, array $nodesById, Collection $sources, Collection $destinations): CompiledRoute
    {
        $sourceNode = $nodesById[$path[0]];
        $destinationNode = $nodesById[$path[array_key_last($path)]];
        $rules = [];
        $steps = [];
        $sourceProvider = null;
        $destinationProvider = null;

        foreach ($path as $id) {
            $node = $nodesById[$id];

            if ($node->type === PipelineNodeType::Filter) {
                foreach ($node->rules() as $rule) {
                    $rules[] = $this->normalizeFilterRule($rule);
                }
            }

            if ($node->type === PipelineNodeType::Transform) {
                array_push($steps, ...$this->transformSteps($node));
            }

            if ($node->type === PipelineNodeType::Mapping) {
                $stored = $node->mapping();

                if (is_array($stored)) {
                    $sourceProvider = is_string($stored['source_provider'] ?? null) ? $stored['source_provider'] : $sourceProvider;
                    $destinationProvider = is_string($stored['destination_provider'] ?? null) ? $stored['destination_provider'] : $destinationProvider;
                }

                foreach (is_array($stored) ? ($stored['steps'] ?? []) : [] as $step) {
                    if (is_array($step)) {
                        $steps[] = $step;
                    }
                }
            }
        }

        $mapping = null;

        if ($steps !== []) {
            $ordered = [];

            foreach ($steps as $index => $step) {
                $step['order'] = $index;
                $ordered[] = $step;
            }

            $mapping = ['version' => 1, 'steps' => $ordered];

            if ($sourceProvider !== null) {
                $mapping['source_provider'] = $sourceProvider;
            }

            if ($destinationProvider !== null) {
                $mapping['destination_provider'] = $destinationProvider;
            }
        }

        /** @var int $sourceId */
        $sourceId = $sourceNode->sourceId();
        /** @var int $destinationId */
        $destinationId = $destinationNode->destinationId();

        return new CompiledRoute(
            sourceId: $sourceId,
            destinationId: $destinationId,
            sourceNodeId: $sourceNode->id,
            destinationNodeId: $destinationNode->id,
            name: $this->sourceName($sourceNode, $sources).' → '.$this->destinationName($destinationNode, $destinations),
            nodeIds: $path,
            filter: $rules === [] ? null : ['rules' => $rules],
            transform: null,
            mapping: $mapping,
        );
    }

    /**
     * @param  Collection<int, Source>  $sources
     * @param  Collection<int, Destination>  $destinations
     */
    private function nodeProblem(PipelineNode $node, Collection $sources, Collection $destinations): ?string
    {
        switch ($node->type) {
            case PipelineNodeType::Source:
                $source = $node->sourceId() === null ? null : $sources->get($node->sourceId());

                if (! $source instanceof Source) {
                    return 'Choose a source for this node.';
                }

                return $source->isArchived() ? 'This source is archived. Choose another or restore it.' : null;

            case PipelineNodeType::Destination:
                $destination = $node->destinationId() === null ? null : $destinations->get($node->destinationId());

                if (! $destination instanceof Destination) {
                    return 'Choose a destination for this node.';
                }

                return $destination->isArchived() ? 'This destination is archived. Choose another or restore it.' : null;

            case PipelineNodeType::Filter:
                $rules = $node->rules();

                if ($rules === []) {
                    return 'Add at least one rule, or remove this filter.';
                }

                foreach ($rules as $index => $rule) {
                    $problem = $this->filterRuleProblem($rule);

                    if ($problem !== null) {
                        return sprintf('Rule %d: %s', $index + 1, $problem);
                    }
                }

                return null;

            case PipelineNodeType::Mapping:
                if ($node->mapping() === null) {
                    return 'Set at least one field, or remove this mapping.';
                }

                return null;

            case PipelineNodeType::Transform:
                $template = $node->template();
                $rules = $node->rules();

                if ($template === '' && $rules === []) {
                    return 'Add at least one rule, or remove this transform.';
                }

                $unknown = TemplatePlaceholders::unknownIn($template);

                if ($unknown !== []) {
                    return 'Unknown placeholder: '.implode(', ', array_map(
                        static fn (string $name): string => '{{'.$name.'}}',
                        $unknown,
                    )).'.';
                }

                foreach ($rules as $index => $rule) {
                    $problem = $this->transformRuleProblem($rule);

                    if ($problem !== null) {
                        return sprintf('Rule %d: %s', $index + 1, $problem);
                    }
                }

                return null;
        }
    }

    /**
     * Mirrors UpdateBroadcastFilterRequest so a graph filter is held to the
     * same standard as the simple broadcast form.
     *
     * @param  array<string, mixed>  $rule
     */
    private function filterRuleProblem(array $rule): ?string
    {
        $type = BroadcastFilterRuleType::tryFrom((string) ($rule['type'] ?? ''));

        if ($type === null) {
            return 'choose a rule type.';
        }

        $field = isset($rule['field']) && is_string($rule['field']) ? $rule['field'] : '';

        if ($type->needsField() && ! in_array($field, self::TEXT_FIELDS, true)) {
            return 'choose the field to check.';
        }

        if (in_array($type, [BroadcastFilterRuleType::TitleMatches, BroadcastFilterRuleType::AuthorEquals], true)
            && $field !== ''
            && ! in_array($field, self::TEXT_FIELDS, true)) {
            return 'choose the field to check.';
        }

        if ($type->needsHours() && $field !== '' && $field !== 'published_at') {
            return 'this rule checks when the item was published.';
        }

        if ($type->needsValue() && blank($rule['value'] ?? null)) {
            return 'a value is required.';
        }

        if ($type->needsValue() && mb_strlen((string) $rule['value']) > 500) {
            return 'the value may be at most 500 characters.';
        }

        if ($type->needsHours()) {
            $hours = $rule['hours'] ?? null;

            if (! is_numeric($hours) || (int) $hours < 1 || (int) $hours > 8760) {
                return 'a number of hours between 1 and 8760 is required.';
            }
        }

        if ($type === BroadcastFilterRuleType::TitleMatches && ! BoundedRegex::isValid((string) $rule['value'])) {
            return 'the value is not a valid regular expression.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function transformRuleProblem(array $rule): ?string
    {
        $type = BroadcastTransformRuleType::tryFrom((string) ($rule['type'] ?? ''));

        if ($type === null) {
            return 'choose a rule type.';
        }

        if ($type->needsLength()) {
            $length = $rule['length'] ?? null;

            if (! is_numeric($length) || (int) $length < 1 || (int) $length > 10000) {
                return 'a length between 1 and 10000 is required.';
            }
        }

        if ($type->needsValue() && blank($rule['value'] ?? null)) {
            return 'a value is required.';
        }

        if ($type->needsPattern()) {
            if (blank($rule['pattern'] ?? null)) {
                return 'a pattern is required.';
            }

            if (! BoundedRegex::isValid((string) $rule['pattern'])) {
                return 'the pattern is not a valid regular expression.';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private function normalizeFilterRule(array $rule): array
    {
        /** @var BroadcastFilterRuleType $type */
        $type = BroadcastFilterRuleType::from((string) $rule['type']);
        $normalized = ['type' => $type->value];

        if ($type->needsField()) {
            $normalized['field'] = (string) $rule['field'];
        }

        if ($type->needsValue()) {
            $normalized['value'] = trim((string) $rule['value']);
        }

        if ($type->needsHours()) {
            $normalized['hours'] = (int) $rule['hours'];
        }

        return $normalized;
    }

    /**
     * A stored template becomes a Set field on the message, then each rule
     * adjusts one field. A rule with no field still adjusts the message, which
     * is how an older transform was stored.
     *
     * @return list<array<string, mixed>>
     */
    private function transformSteps(PipelineNode $node): array
    {
        $steps = [];
        $template = $node->template();

        if ($template !== '') {
            $steps[] = [
                'id' => $node->id.'-template',
                'operation' => 'set_field',
                'order' => 0,
                'config' => ['field' => 'text', 'value' => $template],
            ];
        }

        foreach ($node->rules() as $index => $rule) {
            /** @var BroadcastTransformRuleType $type */
            $type = BroadcastTransformRuleType::from((string) $rule['type']);
            $field = isset($rule['field']) && is_string($rule['field']) && $rule['field'] !== ''
                ? $rule['field']
                : 'text';
            $config = ['field' => $field, 'op' => $type->value];

            if ($type->needsLength()) {
                $config['length'] = (int) $rule['length'];
            }

            if ($type->needsValue()) {
                $config['value'] = (string) $rule['value'];
            }

            if ($type->needsPattern()) {
                $config['pattern'] = (string) $rule['pattern'];
                $config['replacement'] = (string) ($rule['replacement'] ?? '');
            }

            $steps[] = [
                'id' => $node->id.'-rule-'.$index,
                'operation' => 'adjust_field',
                'order' => count($steps),
                'config' => $config,
            ];
        }

        return $steps;
    }

    /**
     * @param  Collection<int, Source>  $sources
     */
    private function sourceName(PipelineNode $node, Collection $sources): string
    {
        $source = $node->sourceId() === null ? null : $sources->get($node->sourceId());

        return $source instanceof Source ? $source->name : 'Source';
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     */
    private function destinationName(PipelineNode $node, Collection $destinations): string
    {
        $destination = $node->destinationId() === null ? null : $destinations->get($node->destinationId());

        return $destination instanceof Destination ? $destination->name : 'Destination';
    }

    private function unconnectedMessage(PipelineNode $node): string
    {
        return match ($node->type) {
            PipelineNodeType::Source => 'Not connected. Drag from its handle to a filter, mapping, transform or destination.',
            PipelineNodeType::Destination => 'Not connected. Nothing reaches this destination yet.',
            default => 'Not connected. Place it between a source and a destination.',
        };
    }

    private function incompletePathMessage(PipelineNode $node): string
    {
        return match ($node->type) {
            PipelineNodeType::Source => 'This source does not reach a destination yet.',
            PipelineNodeType::Destination => 'No source reaches this destination yet.',
            default => 'Not on a complete path from a source to a destination.',
        };
    }
}
