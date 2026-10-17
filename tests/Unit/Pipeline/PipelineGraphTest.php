<?php

namespace Tests\Unit\Pipeline;

use App\Enums\PipelineNodeType;
use App\Exceptions\InvalidPipelineGraphException;
use App\Support\Pipeline\PipelineGraph;
use PHPUnit\Framework\TestCase;

class PipelineGraphTest extends TestCase
{
    public function test_parses_and_normalizes_a_well_formed_graph(): void
    {
        $graph = PipelineGraph::fromArray([
            'nodes' => [
                ['id' => 'src', 'type' => 'source', 'x' => 10.6, 'y' => -3.2, 'config' => ['source_id' => '7', 'label' => 'Feed']],
                ['id' => 'flt', 'type' => 'filter', 'x' => 200, 'y' => 0, 'config' => ['rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'cats', 'hours' => '', 'unknown' => 'dropped']]]],
                ['id' => 'dst', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => 3, 'secret' => 'dropped']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'src', 'to' => 'flt'],
                ['id' => 'e2', 'from' => 'flt', 'to' => 'dst'],
            ],
            'viewport' => ['x' => 12, 'y' => 8, 'zoom' => 1.5],
        ]);

        $this->assertCount(3, $graph->nodes);
        $this->assertCount(2, $graph->edges);
        $this->assertFalse($graph->isEmpty());

        $source = $graph->node('src');
        $this->assertNotNull($source);
        $this->assertSame(PipelineNodeType::Source, $source->type);
        $this->assertSame(7, $source->sourceId());
        $this->assertSame(10.6, $source->x);
        $this->assertSame(-3.2, $source->y);

        $filter = $graph->node('flt');
        $this->assertNotNull($filter);
        $this->assertSame([['type' => 'contains', 'field' => 'title', 'value' => 'cats']], $filter->rules());

        $destination = $graph->node('dst');
        $this->assertNotNull($destination);
        $this->assertSame(['destination_id' => 3], $destination->config);

        $array = $graph->toArray();
        $this->assertSame(PipelineGraph::VERSION, $array['version']);
        $this->assertSame(['x' => 12.0, 'y' => 8.0, 'zoom' => 1.5], $array['viewport']);
        $this->assertSame(['src' => ['flt'], 'flt' => ['dst'], 'dst' => []], $graph->adjacency());
    }

    public function test_an_out_of_range_viewport_is_dropped(): void
    {
        $graph = PipelineGraph::fromArray([
            'nodes' => [],
            'edges' => [],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 9],
        ]);

        $this->assertNull($graph->viewport);
        $this->assertArrayNotHasKey('viewport', $graph->toArray());
    }

    public function test_an_empty_document_is_an_empty_graph(): void
    {
        $graph = PipelineGraph::fromArray([]);

        $this->assertTrue($graph->isEmpty());
        $this->assertSame([], $graph->toArray()['nodes']);
        $this->assertSame([], $graph->toArray()['edges']);
    }

    public function test_rejects_an_unknown_node_type(): void
    {
        $this->expectInvalidGraph('graph.nodes.0.type', [
            'nodes' => [['id' => 'a', 'type' => 'webhook', 'x' => 0, 'y' => 0, 'config' => []]],
            'edges' => [],
        ]);
    }

    public function test_rejects_duplicate_node_ids(): void
    {
        $this->expectInvalidGraph('graph.nodes.1.id', [
            'nodes' => [
                ['id' => 'a', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => []],
                ['id' => 'a', 'type' => 'destination', 'x' => 0, 'y' => 0, 'config' => []],
            ],
            'edges' => [],
        ]);
    }

    public function test_rejects_an_edge_to_a_missing_node(): void
    {
        $this->expectInvalidGraph('graph.edges.0.nodes', [
            'nodes' => [['id' => 'a', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => []]],
            'edges' => [['id' => 'e', 'from' => 'a', 'to' => 'ghost']],
        ]);
    }

    public function test_rejects_an_edge_between_incompatible_types(): void
    {
        $this->expectInvalidGraph('graph.edges.0.nodes', [
            'nodes' => [
                ['id' => 't1', 'type' => 'transform', 'x' => 0, 'y' => 0, 'config' => ['template' => '{{title}}']],
                ['id' => 't2', 'type' => 'transform', 'x' => 0, 'y' => 0, 'config' => ['template' => '{{title}}']],
            ],
            'edges' => [['id' => 'e', 'from' => 't1', 'to' => 't2']],
        ]);
    }

    public function test_rejects_an_edge_out_of_a_destination(): void
    {
        $this->expectInvalidGraph('graph.edges.0.nodes', [
            'nodes' => [
                ['id' => 'd', 'type' => 'destination', 'x' => 0, 'y' => 0, 'config' => []],
                ['id' => 'f', 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => []],
            ],
            'edges' => [['id' => 'e', 'from' => 'd', 'to' => 'f']],
        ]);
    }

    public function test_rejects_a_cycle(): void
    {
        $this->expectInvalidGraph('graph.edges', [
            'nodes' => [
                ['id' => 'f1', 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => []],
                ['id' => 'f2', 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'f1', 'to' => 'f2'],
                ['id' => 'e2', 'from' => 'f2', 'to' => 'f1'],
            ],
        ]);
    }

    public function test_rejects_too_many_nodes(): void
    {
        $nodes = [];

        for ($index = 0; $index <= PipelineGraph::MAX_NODES; $index++) {
            $nodes[] = ['id' => 'n'.$index, 'type' => 'filter', 'x' => 0, 'y' => 0, 'config' => []];
        }

        $this->expectInvalidGraph('graph.nodes', ['nodes' => $nodes, 'edges' => []]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function expectInvalidGraph(string $errorKey, array $document): void
    {
        try {
            PipelineGraph::fromArray($document);
        } catch (InvalidPipelineGraphException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors, implode(' | ', array_keys($exception->errors)));

            return;
        }

        $this->fail('The graph was accepted.');
    }
}
