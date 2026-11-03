<?php

namespace Tests\Unit\Pipeline;

use App\Models\Destination;
use App\Models\Source;
use App\Support\Pipeline\CompiledRoute;
use App\Support\Pipeline\PipelineCompilation;
use App\Support\Pipeline\PipelineGraph;
use App\Support\Pipeline\PipelineGraphCompiler;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Compiles against in-memory models: the Eloquent casts need the container,
 * but nothing here touches the database.
 */
class PipelineGraphCompilerTest extends TestCase
{
    public function test_every_source_to_destination_path_becomes_a_route(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s1', 'source', ['source_id' => 1]),
                $this->node('s2', 'source', ['source_id' => 2]),
                $this->node('f', 'filter', ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'cats']]]),
                $this->node('t', 'transform', ['template' => 'News: {{title}}', 'rules' => [['type' => 'truncate', 'length' => '140']]]),
                $this->node('d1', 'destination', ['destination_id' => 10]),
                $this->node('d2', 'destination', ['destination_id' => 20]),
            ],
            'edges' => [
                $this->edge('s1', 'f'),
                $this->edge('s2', 'f'),
                $this->edge('f', 't'),
                $this->edge('t', 'd1'),
                $this->edge('s2', 'd2'),
            ],
        ]);

        $this->assertFalse($compilation->hasErrors());
        $this->assertSame([], $compilation->issues);

        $keys = array_map(static fn (CompiledRoute $route): string => $route->key(), $compilation->routes);
        sort($keys);
        $this->assertSame(['1:10', '2:10', '2:20'], $keys);

        $filtered = $this->routeByKey($compilation, '1:10');
        $this->assertSame(['s1', 'f', 't', 'd1'], $filtered->nodeIds);
        $this->assertSame('Feed one → Channel ten', $filtered->name);
        $this->assertSame(['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'cats']]], $filtered->filter);
        $this->assertNull($filtered->transform);
        $this->assertSame('set_field', $filtered->mapping['steps'][0]['operation']);
        $this->assertSame(['field' => 'text', 'value' => 'News: {{title}}'], $filtered->mapping['steps'][0]['config']);
        $this->assertSame('adjust_field', $filtered->mapping['steps'][1]['operation']);
        $this->assertSame(['field' => 'text', 'op' => 'truncate', 'length' => 140], $filtered->mapping['steps'][1]['config']);

        $direct = $this->routeByKey($compilation, '2:20');
        $this->assertNull($direct->filter);
        $this->assertNull($direct->transform);
    }

    public function test_each_mapping_node_sets_the_fields_its_path_sends(): void
    {
        $mapping = [
            'version' => 1,
            'steps' => [
                ['id' => 'news-text', 'operation' => 'set_field', 'order' => 0, 'config' => ['field' => 'text', 'value' => 'News: {{title}}']],
            ],
            'source_provider' => 'fake',
            'destination_provider' => 'recording',
        ];

        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('m', 'mapping', ['mapping' => $mapping]),
                $this->node('t', 'transform', ['template' => 'Alert: {{title}}']),
                $this->node('d1', 'destination', ['destination_id' => 10]),
                $this->node('d2', 'destination', ['destination_id' => 20]),
            ],
            'edges' => [
                $this->edge('s', 'm'),
                $this->edge('m', 'd1'),
                $this->edge('s', 't'),
                $this->edge('t', 'd2'),
            ],
        ]);

        $this->assertFalse($compilation->hasErrors());

        $mapped = $this->routeByKey($compilation, '1:10');
        $this->assertSame(['s', 'm', 'd1'], $mapped->nodeIds);
        $this->assertSame($mapping, $mapped->mapping);
        $this->assertNull($mapped->transform);

        $templated = $this->routeByKey($compilation, '1:20');
        $this->assertNull($templated->transform);
        $this->assertSame('Alert: {{title}}', $templated->mapping['steps'][0]['config']['value']);
    }

    public function test_a_transform_runs_before_or_after_the_mapping_in_path_order(): void
    {
        $mapping = [
            'version' => 1,
            'steps' => [
                ['id' => 'text', 'operation' => 'set_field', 'order' => 0, 'config' => ['field' => 'text', 'value' => '{{title}}']],
            ],
        ];

        $before = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('t', 'transform', ['rules' => [['type' => 'strip_html', 'field' => 'title']]]),
                $this->node('m', 'mapping', ['mapping' => $mapping]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [$this->edge('s', 't'), $this->edge('t', 'm'), $this->edge('m', 'd')],
        ]);

        $after = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('m', 'mapping', ['mapping' => $mapping]),
                $this->node('t', 'transform', ['rules' => [['type' => 'strip_html', 'field' => 'text']]]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [$this->edge('s', 'm'), $this->edge('m', 't'), $this->edge('t', 'd')],
        ]);

        $this->assertFalse($before->hasErrors());
        $this->assertSame(
            ['adjust_field', 'set_field'],
            array_map(static fn (array $step): string => $step['operation'], $this->routeByKey($before, '1:10')->mapping['steps']),
        );
        $this->assertSame('title', $this->routeByKey($before, '1:10')->mapping['steps'][0]['config']['field']);

        $this->assertFalse($after->hasErrors());
        $this->assertSame(
            ['set_field', 'adjust_field'],
            array_map(static fn (array $step): string => $step['operation'], $this->routeByKey($after, '1:10')->mapping['steps']),
        );
        $this->assertSame('text', $this->routeByKey($after, '1:10')->mapping['steps'][1]['config']['field']);
    }

    public function test_a_blank_mapping_blocks_its_path(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('m', 'mapping', ['mapping' => [
                    'steps' => [
                        ['id' => 'blank', 'operation' => 'set_field', 'order' => 0, 'config' => ['field' => 'text', 'value' => '   ']],
                    ],
                ]]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [
                $this->edge('s', 'm'),
                $this->edge('m', 'd'),
            ],
        ]);

        $this->assertTrue($compilation->hasErrors());
        $this->assertSame([], $compilation->routes);
        $messages = array_map(static fn ($issue): string => $issue->message, $compilation->issues);
        $this->assertContains('Set at least one field, or remove this mapping.', $messages);
    }

    public function test_chained_filters_are_anded_into_one_rule_list(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('f1', 'filter', ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'cats']]]),
                $this->node('f2', 'filter', ['rules' => [['type' => 'published_after', 'hours' => 24]]]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [$this->edge('s', 'f1'), $this->edge('f1', 'f2'), $this->edge('f2', 'd')],
        ]);

        $this->assertCount(1, $compilation->routes);
        $this->assertSame([
            ['type' => 'contains', 'field' => 'title', 'value' => 'cats'],
            ['type' => 'published_after', 'hours' => 24],
        ], $compilation->routes[0]->filter['rules']);
    }

    public function test_parallel_paths_between_the_same_pair_each_become_a_route(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('f1', 'filter', ['rules' => [['type' => 'title_matches', 'field' => 'title', 'value' => '\[RFC\]']]]),
                $this->node('f2', 'filter', ['rules' => [['type' => 'title_matches', 'field' => 'title', 'value' => '\[VOTE\]']]]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [$this->edge('s', 'f1'), $this->edge('s', 'f2'), $this->edge('f1', 'd'), $this->edge('f2', 'd')],
        ]);

        $this->assertFalse($compilation->hasErrors());
        $this->assertCount(2, $compilation->routes);
        $this->assertSame(['1:10', '1:10'], array_map(static fn (CompiledRoute $route): string => $route->key(), $compilation->routes));
        $this->assertNotSame($compilation->routes[0]->pathKey(), $compilation->routes[1]->pathKey());

        $values = array_map(static fn (CompiledRoute $route): string => $route->filter['rules'][0]['value'] ?? '', $compilation->routes);
        sort($values);
        $this->assertSame(['\[RFC\]', '\[VOTE\]'], $values);

        $this->assertCount(1, $compilation->issues);
        $this->assertSame('warning', $compilation->issues[0]->level);
        $this->assertSame('d', $compilation->issues[0]->nodeId);
        $this->assertStringContainsString('2 paths', $compilation->issues[0]->message);
        $this->assertStringContainsString('sent once', $compilation->issues[0]->message);
        $this->assertSame($compilation->routes[0]->groupKey(), $compilation->routes[1]->groupKey());
        $this->assertSame([0, 1], array_map(static fn (CompiledRoute $route): int => $route->order, $compilation->routes));
    }

    public function test_two_nodes_for_the_same_records_each_become_a_route(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s1', 'source', ['source_id' => 1]),
                $this->node('s2', 'source', ['source_id' => 1]),
                $this->node('d', 'destination', ['destination_id' => 10]),
            ],
            'edges' => [$this->edge('s1', 'd'), $this->edge('s2', 'd')],
        ]);

        $this->assertCount(2, $compilation->routes);
        $this->assertSame(['1:10', '1:10'], array_map(static fn (CompiledRoute $route): string => $route->key(), $compilation->routes));
        $this->assertSame(['s1', 'd'], $compilation->routes[0]->nodeIds);
        $this->assertSame(['s2', 'd'], $compilation->routes[1]->nodeIds);
        $this->assertFalse($compilation->hasErrors());
        $this->assertSame('warning', $compilation->issues[0]->level);
        $this->assertCount(1, $compilation->issues);
        $this->assertSame('s2', $compilation->issues[0]->nodeId);
        $this->assertStringContainsString('another source node', $compilation->issues[0]->message);
        $this->assertStringContainsString('sent twice', $compilation->issues[0]->message);
        $this->assertNotSame($compilation->routes[0]->pathKey(), $compilation->routes[1]->pathKey());
        $this->assertNotSame($compilation->routes[0]->groupKey(), $compilation->routes[1]->groupKey());
    }

    public function test_a_broken_node_blocks_its_paths_and_is_reported(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('empty', 'filter', ['rules' => []]),
                $this->node('d1', 'destination', ['destination_id' => 10]),
                $this->node('missing', 'destination', ['destination_id' => null]),
                $this->node('archived', 'destination', ['destination_id' => 30]),
                $this->node('gone', 'source', ['source_id' => 999]),
                $this->node('bad', 'transform', ['template' => 'Hi {{nope}}']),
            ],
            'edges' => [
                $this->edge('s', 'empty'),
                $this->edge('empty', 'd1'),
                $this->edge('s', 'missing'),
                $this->edge('s', 'archived'),
                $this->edge('gone', 'd1'),
                $this->edge('s', 'bad'),
                $this->edge('bad', 'd1'),
            ],
        ]);

        $this->assertSame([], $compilation->routes);

        $errors = [];

        foreach ($compilation->issues as $issue) {
            if ($issue->isError()) {
                $errors[$issue->nodeId] = $issue->message;
            }
        }

        $this->assertSame('Add at least one rule, or remove this filter.', $errors['empty']);
        $this->assertSame('Choose a destination for this node.', $errors['missing']);
        $this->assertSame('This destination is archived. Choose another or restore it.', $errors['archived']);
        $this->assertSame('Choose a source for this node.', $errors['gone']);
        $this->assertSame('Unknown placeholder: {{nope}}.', $errors['bad']);
        $this->assertArrayNotHasKey('s', $errors);
        $this->assertArrayNotHasKey('d1', $errors);
    }

    public function test_unconnected_and_dangling_nodes_only_warn(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('s', 'source', ['source_id' => 1]),
                $this->node('lonely', 'destination', ['destination_id' => 10]),
                $this->node('f', 'filter', ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'x']]]),
            ],
            'edges' => [$this->edge('s', 'f')],
        ]);

        $this->assertSame([], $compilation->routes);
        $this->assertFalse($compilation->hasErrors());

        $warnings = [];

        foreach ($compilation->issues as $issue) {
            $warnings[$issue->nodeId] = $issue->message;
        }

        $this->assertSame('Not connected. Nothing reaches this destination yet.', $warnings['lonely']);
        $this->assertSame('This source does not reach a destination yet.', $warnings['s']);
        $this->assertSame('Not on a complete path from a source to a destination.', $warnings['f']);
    }

    public function test_invalid_rules_are_reported_with_their_position(): void
    {
        $compilation = $this->compile([
            'nodes' => [
                $this->node('f', 'filter', ['rules' => [
                    ['type' => 'contains', 'field' => 'title', 'value' => 'ok'],
                    ['type' => 'title_matches', 'value' => '(unclosed'],
                ]]),
                $this->node('t', 'transform', ['rules' => [['type' => 'truncate', 'length' => 0]]]),
            ],
            'edges' => [],
        ]);

        $messages = array_map(static fn ($issue): string => $issue->message, $compilation->issues);

        $this->assertContains('Rule 2: the value is not a valid regular expression.', $messages);
        $this->assertContains('Rule 1: a length between 1 and 10000 is required.', $messages);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function compile(array $document): PipelineCompilation
    {
        $sources = new Collection([
            1 => $this->source(1, 'Feed one'),
            2 => $this->source(2, 'Feed two'),
        ]);
        $destinations = new Collection([
            10 => $this->destination(10, 'Channel ten'),
            20 => $this->destination(20, 'Channel twenty'),
            30 => $this->destination(30, 'Old channel', archived: true),
        ]);

        return (new PipelineGraphCompiler)->compileWith(PipelineGraph::fromArray($document), $sources, $destinations);
    }

    private function routeByKey(PipelineCompilation $compilation, string $key): CompiledRoute
    {
        foreach ($compilation->routes as $route) {
            if ($route->key() === $key) {
                return $route;
            }
        }

        $this->fail("No route {$key}.");
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function node(string $id, string $type, array $config): array
    {
        return ['id' => $id, 'type' => $type, 'x' => 0, 'y' => 0, 'config' => $config];
    }

    /**
     * @return array<string, string>
     */
    private function edge(string $from, string $to): array
    {
        return ['id' => "{$from}-{$to}", 'from' => $from, 'to' => $to];
    }

    private function source(int $id, string $name): Source
    {
        return (new Source)->forceFill(['id' => $id, 'name' => $name, 'archived_at' => null]);
    }

    private function destination(int $id, string $name, bool $archived = false): Destination
    {
        return (new Destination)->forceFill([
            'id' => $id,
            'name' => $name,
            'archived_at' => $archived ? '2026-01-01 00:00:00' : null,
        ]);
    }
}
