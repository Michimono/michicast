<?php

namespace App\Http\Controllers;

use App\Models\BroadcastRun;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Models\Source;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A compact, read-only account of one source for the pipeline inspector: its
 * configuration, the fields its items carry, the most recently fetched item
 * as actually observed, and the latest runs that read from it.
 */
class SourceSummaryController extends Controller
{
    private const RECENT_RUNS = 6;

    public function __construct(
        private PipelineEditorPayload $payload,
        private SecretRedactor $secrets,
    ) {}

    public function show(Organization $organization, Source $source): JsonResponse
    {
        Gate::authorize('viewAny', [Source::class, $source->project]);

        $latest = ContentItem::query()
            ->where('source_id', $source->getKey())
            ->latest('discovered_at')
            ->latest('id')
            ->first();

        $runs = BroadcastRun::query()
            ->whereHas('broadcast', fn ($query) => $query->where('source_id', $source->getKey()))
            ->with([
                'broadcast:id,name,source_id,destination_id',
                'projectRun:id,is_dry',
            ])
            ->latest('started_at')
            ->latest('id')
            ->limit(self::RECENT_RUNS)
            ->get();

        return response()->json([
            'source' => $this->payload->source($source),
            'fields' => $this->payload->schemaFields('source', $source->type),
            'item_count' => ContentItem::query()->where('source_id', $source->getKey())->count(),
            'sample' => $latest === null ? null : [
                'observed_at' => $latest->discovered_at->toIso8601String(),
                'fields' => array_filter([
                    'title' => $this->text($latest->title),
                    'body' => $this->text($latest->body, 600),
                    'link' => $this->text($latest->canonical_url),
                    'author' => $this->text($latest->author),
                    'published_at' => $latest->published_at?->toIso8601String(),
                    'external_id' => $this->text($latest->external_id),
                    'media_count' => is_array($latest->media) ? count($latest->media) : 0,
                ], static fn (mixed $value): bool => $value !== null),
            ],
            'activity' => $runs->map(fn (BroadcastRun $run): array => [
                'id' => $run->getKey(),
                'run_id' => $run->project_run_id,
                'route' => $run->broadcast?->name,
                'started_at' => $run->started_at->toIso8601String(),
                'is_dry' => (bool) $run->projectRun?->is_dry,
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'items_found' => $run->items_found,
                'items_new' => $run->items_new,
                'error' => $this->text($run->error_detail, 300),
            ])->values()->all(),
        ]);
    }

    private function text(mixed $value, int $limit = 255): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = (string) $this->secrets->redact($value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 1).'…' : $value;
    }
}
