<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineNodeType;
use App\Enums\PipelineTraceStatus;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\ContentItem;
use App\Models\PipelineEdgeTrace;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\BroadcastFilters\BroadcastFilterEvaluator;
use App\Support\Mapping\ContentMapper;
use App\Support\SecretRedactor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a finished dry run into one trace per connection of the graph: which
 * items entered the edge, and whether each one went on past the node it
 * leads to.
 *
 * The runner works per route with the path's filters already ANDed, so a
 * route's dry report only says whether an item would publish. To show where
 * an item stopped, each filter node is evaluated again on its own against the
 * items the run fetched (in memory, nothing refetched), and the transform is
 * rendered through the same mapper the run used. Route-level guards — backfill
 * and already-delivered — are reported on the connection into the destination,
 * which is the node they protect.
 *
 * Samples are bounded and redacted before they are stored. Only the traces of
 * the newest few dry runs are kept per project.
 */
class PipelineTraceRecorder
{
    public const MAX_ITEMS_PER_EDGE = 10;

    public const MAX_TEXT_LENGTH = 1500;

    public const RUNS_TO_KEEP = 3;

    public function __construct(
        private BroadcastFilterEvaluator $filters,
        private ContentMapper $mapper,
        private SecretRedactor $secrets,
    ) {}

    /**
     * Build and store the traces of $run. Returns the stored rows keyed by edge id.
     *
     * @return array<string, PipelineEdgeTrace>
     */
    public function record(Project $project, PipelineGraph $graph, PipelineCompilation $compilation, ProjectRun $run): array
    {
        $traces = $this->build($graph, $compilation, $run);
        $now = now();

        DB::transaction(function () use ($project, $run, $traces, $now): void {
            PipelineEdgeTrace::query()->where('project_run_id', $run->getKey())->delete();

            if ($traces !== []) {
                PipelineEdgeTrace::query()->insert(array_map(fn (array $trace): array => [
                    'project_id' => $project->getKey(),
                    'project_run_id' => $run->getKey(),
                    'edge_id' => $trace['edge_id'],
                    'from_node_id' => $trace['from_node_id'],
                    'to_node_id' => $trace['to_node_id'],
                    'status' => $trace['status']->value,
                    'items_in' => $trace['items_in'],
                    'items_out' => $trace['items_out'],
                    'summary' => $trace['summary'],
                    'items' => json_encode($trace['items'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $traces));
            }

            $this->prune($project);
        });

        return PipelineEdgeTrace::query()
            ->where('project_run_id', $run->getKey())
            ->get()
            ->keyBy('edge_id')
            ->all();
    }

    /**
     * The traces of the newest dry run that has any, keyed by edge id.
     *
     * @return array<string, PipelineEdgeTrace>
     */
    public function latest(Project $project): array
    {
        $runId = PipelineEdgeTrace::query()
            ->where('project_id', $project->getKey())
            ->max('project_run_id');

        if ($runId === null) {
            return [];
        }

        return PipelineEdgeTrace::query()
            ->where('project_id', $project->getKey())
            ->where('project_run_id', $runId)
            ->get()
            ->keyBy('edge_id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(PipelineEdgeTrace $trace, bool $withItems = true): array
    {
        $payload = [
            'edge_id' => $trace->edge_id,
            'run_id' => $trace->project_run_id,
            'from_node_id' => $trace->from_node_id,
            'to_node_id' => $trace->to_node_id,
            'status' => $trace->status->value,
            'status_label' => $trace->status->label(),
            'items_in' => $trace->items_in,
            'items_out' => $trace->items_out,
            'summary' => $trace->summary,
            'recorded_at' => $trace->created_at?->toIso8601String(),
        ];

        if ($withItems) {
            $payload['items'] = $trace->items ?? [];
            $payload['sample_limit'] = self::MAX_ITEMS_PER_EDGE;
        }

        return $payload;
    }

    /**
     * @return list<array{edge_id: string, from_node_id: string, to_node_id: string, status: PipelineTraceStatus, items_in: int, items_out: int, summary: string|null, items: list<array<string, mixed>>}>
     */
    private function build(PipelineGraph $graph, PipelineCompilation $compilation, ProjectRun $run): array
    {
        $nodesById = [];

        foreach ($graph->nodes as $node) {
            $nodesById[$node->id] = $node;
        }

        $edgeIds = [];

        foreach ($graph->edges as $edge) {
            $edgeIds[$edge->from.'>'.$edge->to] = $edge->id;
        }

        /** @var Collection<int, BroadcastRun> $broadcastRuns */
        $broadcastRuns = $run->broadcastRuns()
            ->with(['broadcast.source', 'broadcast.destination'])
            ->get();

        $runsByPair = [];

        foreach ($broadcastRuns as $broadcastRun) {
            $broadcast = $broadcastRun->broadcast;

            if ($broadcast instanceof Broadcast) {
                $runsByPair[$broadcast->source_id.':'.$broadcast->destination_id] = $broadcastRun;
            }
        }

        /** @var array<string, array{from: string, to: string, status: PipelineTraceStatus|null, items_in: int, items_out: int, summary: string|null, items: array<string, array<string, mixed>>}> $traces */
        $traces = [];

        $touch = function (string $from, string $to) use (&$traces): void {
            $traces[$from.'>'.$to] ??= [
                'from' => $from,
                'to' => $to,
                'status' => null,
                'items_in' => 0,
                'items_out' => 0,
                'summary' => null,
                'items' => [],
            ];
        };

        foreach ($compilation->routes as $route) {
            $broadcastRun = $runsByPair[$route->key()] ?? null;
            $path = $route->nodeIds;

            if (! $broadcastRun instanceof BroadcastRun) {
                for ($index = 0; $index < count($path) - 1; $index++) {
                    $touch($path[$index], $path[$index + 1]);
                    $trace = &$traces[$path[$index].'>'.$path[$index + 1]];

                    if ($trace['status'] === null) {
                        $trace['status'] = PipelineTraceStatus::Skipped;
                        $trace['summary'] = 'This route was not part of the test. It is disabled, paused, or was saved after the test ran.';
                    }

                    unset($trace);
                }

                continue;
            }

            if (in_array($broadcastRun->status, [RunStatus::Failed, RunStatus::QuotaExceeded], true)) {
                $summary = $this->text($broadcastRun->error_detail) ?? $broadcastRun->status->label();

                for ($index = 0; $index < count($path) - 1; $index++) {
                    $touch($path[$index], $path[$index + 1]);
                    $trace = &$traces[$path[$index].'>'.$path[$index + 1]];

                    if ($trace['status'] !== PipelineTraceStatus::Flowed) {
                        $trace['status'] = PipelineTraceStatus::Failed;
                        $trace['summary'] = $summary;
                    }

                    unset($trace);
                }

                continue;
            }

            $this->walk($route, $broadcastRun, $nodesById, $touch, $traces);
        }

        $result = [];

        foreach ($traces as $key => $trace) {
            if (! isset($edgeIds[$key])) {
                continue;
            }

            $items = array_values($trace['items']);

            $result[] = [
                'edge_id' => $edgeIds[$key],
                'from_node_id' => $trace['from'],
                'to_node_id' => $trace['to'],
                'status' => $trace['status'] ?? PipelineTraceStatus::Empty,
                'items_in' => $trace['items_in'],
                'items_out' => $trace['items_out'],
                'summary' => $trace['summary'],
                'items' => array_slice($items, 0, self::MAX_ITEMS_PER_EDGE),
            ];
        }

        return $result;
    }

    /**
     * Carry the run's items along one route, recording each connection.
     *
     * @param  array<string, PipelineNode>  $nodesById
     * @param  callable(string, string): void  $touch
     * @param  array<string, array{from: string, to: string, status: PipelineTraceStatus|null, items_in: int, items_out: int, summary: string|null, items: array<string, array<string, mixed>>}>  $traces
     */
    private function walk(CompiledRoute $route, BroadcastRun $broadcastRun, array $nodesById, callable $touch, array &$traces): void
    {
        $broadcast = $broadcastRun->broadcast;
        $report = $broadcastRun->dry_report ?? [];
        $ids = [];
        $outcomes = [];

        foreach ($report as $entry) {
            if (! isset($entry['content_item_id'])) {
                continue;
            }

            $id = (int) $entry['content_item_id'];
            $ids[] = $id;
            $outcomes[$id] = $entry;
        }

        $items = $ids === []
            ? collect()
            : ContentItem::query()
                ->whereKey($ids)
                ->where('project_id', $broadcast->project_id)
                ->get()
                ->keyBy(fn (ContentItem $item): int => (int) $item->getKey());

        /** @var list<array{item: ContentItem, payload: array<string, mixed>}> $current */
        $current = [];

        foreach ($ids as $id) {
            $item = $items->get($id);

            if ($item instanceof ContentItem) {
                $current[] = ['item' => $item, 'payload' => $this->itemPayload($item)];
            }
        }

        $path = $route->nodeIds;

        for ($index = 0; $index < count($path) - 1; $index++) {
            $from = $path[$index];
            $to = $path[$index + 1];
            $target = $nodesById[$to];
            $touch($from, $to);
            $trace = &$traces[$from.'>'.$to];
            $next = [];

            foreach ($current as $entry) {
                $item = $entry['item'];
                $status = 'passed';
                $reason = null;
                $payloadAfter = $entry['payload'];

                switch ($target->type) {
                    case PipelineNodeType::Filter:
                        $decision = $this->filters->evaluate(['rules' => $target->rules()], $item);

                        if (! $decision->passed) {
                            $status = 'stopped';
                            $reason = $decision->skipReason ?? 'Filtered out by this filter.';
                        }
                        break;

                    case PipelineNodeType::Transform:
                        $payloadAfter = $this->renderedPayload($broadcast, $item);
                        break;

                    case PipelineNodeType::Destination:
                        $outcome = $outcomes[(int) $item->getKey()] ?? [];

                        if (($outcome['outcome'] ?? null) === 'publish') {
                            $status = 'delivered';
                        } else {
                            $status = 'stopped';
                            $reason = $this->text($outcome['skip_reason'] ?? null) ?? 'The route would not publish this item.';
                        }
                        break;

                    default:
                        break;
                }

                // A connection shared by two routes (fan-out behind a filter, fan-in
                // into a destination) sees the same item once per route; keep one
                // entry per item and let a pass win over a stop.
                $key = (string) $item->getKey();

                if (! isset($trace['items'][$key]) || ($trace['items'][$key]['status'] === 'stopped' && $status !== 'stopped')) {
                    $trace['items'][$key] = [
                        'content_item_id' => (int) $item->getKey(),
                        'title' => $this->text($item->title) ?? $this->text($item->canonical_url) ?? 'Untitled item',
                        'canonical_url' => $this->text($item->canonical_url),
                        'status' => $status,
                        'reason' => $reason,
                        'payload' => $entry['payload'],
                    ];
                }

                if ($status !== 'stopped') {
                    $next[] = ['item' => $item, 'payload' => $payloadAfter];
                }
            }

            $trace['items_in'] = count($trace['items']);
            $trace['items_out'] = count(array_filter(
                $trace['items'],
                static fn (array $entry): bool => $entry['status'] !== 'stopped',
            ));

            if ($trace['items_in'] > 0) {
                $trace['status'] = PipelineTraceStatus::Flowed;
                $trace['summary'] = null;
            } elseif ($trace['status'] === null || $trace['status'] === PipelineTraceStatus::Skipped) {
                $trace['status'] = PipelineTraceStatus::Empty;
                $trace['summary'] = $index === 0
                    ? 'The source returned nothing during the test.'
                    : 'Nothing passed the previous node during the test.';
            }

            unset($trace);
            $current = $next;
        }
    }

    /**
     * The normalized item as the source emitted it: the fields a filter or
     * template can read, bounded and redacted.
     *
     * @return array<string, mixed>
     */
    private function itemPayload(ContentItem $item): array
    {
        $media = is_array($item->media) ? $item->media : [];

        return array_filter([
            'title' => $this->text($item->title),
            'body' => $this->text($item->body),
            'link' => $this->text($item->canonical_url),
            'author' => $this->text($item->author),
            'published_at' => $item->published_at?->toIso8601String(),
            'external_id' => $this->text($item->external_id),
            'media' => $media === [] ? null : array_map(
                fn (array $entry): array => array_filter([
                    'type' => $this->text(is_string($entry['type'] ?? null) ? $entry['type'] : null),
                    'url' => $this->text(is_string($entry['url'] ?? null) ? $entry['url'] : null),
                ], static fn (mixed $value): bool => $value !== null),
                array_slice(array_values(array_filter($media, 'is_array')), 0, 5),
            ),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The message the route would hand to the destination.
     *
     * @return array<string, mixed>
     */
    private function renderedPayload(Broadcast $broadcast, ContentItem $item): array
    {
        try {
            $message = $this->mapper->prepare($broadcast, $item, $broadcast->source?->name);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'The transform could not render this item: '.$this->text($e->getMessage())];
        }

        $fields = [];

        foreach ($message->fields as $name => $value) {
            if ($name !== 'text' && (is_scalar($value) || $value === null)) {
                $fields[$name] = is_string($value) ? $this->text($value) : $value;
            }
        }

        return array_filter([
            'text' => $this->text($message->body) ?? '',
            'link' => $this->text($message->link),
            'media_count' => $message->media === [] ? null : count($message->media),
            'fields' => $fields === [] ? null : $fields,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = (string) $this->secrets->redact($value);

        if (trim($value) === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            return mb_substr($value, 0, self::MAX_TEXT_LENGTH - 1).'…';
        }

        return $value;
    }

    private function prune(Project $project): void
    {
        $keep = PipelineEdgeTrace::query()
            ->where('project_id', $project->getKey())
            ->select('project_run_id')
            ->distinct()
            ->orderByDesc('project_run_id')
            ->limit(self::RUNS_TO_KEEP)
            ->pluck('project_run_id')
            ->all();

        PipelineEdgeTrace::query()
            ->where('project_id', $project->getKey())
            ->whereNotIn('project_run_id', $keep)
            ->delete();
    }
}
