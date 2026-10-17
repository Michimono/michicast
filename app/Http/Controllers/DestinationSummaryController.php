<?php

namespace App\Http\Controllers;

use App\Models\ContentDelivery;
use App\Models\Destination;
use App\Models\Organization;
use App\Support\DestinationProviderRegistry;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A compact, read-only account of one destination for the pipeline
 * inspector: its settings (secrets masked), the fields its messages accept
 * according to the provider, and the latest delivery attempts to it.
 */
class DestinationSummaryController extends Controller
{
    private const RECENT_DELIVERIES = 8;

    public function __construct(
        private PipelineEditorPayload $payload,
        private DestinationProviderRegistry $providers,
        private SecretRedactor $secrets,
    ) {}

    public function show(Organization $organization, Destination $destination): JsonResponse
    {
        Gate::authorize('viewAny', [Destination::class, $destination->project]);

        $deliveries = ContentDelivery::query()
            ->where('destination_id', $destination->getKey())
            ->with(['contentItem:id,title,canonical_url', 'broadcast:id,name', 'broadcastRun:id,project_run_id'])
            ->latest('attempted_at')
            ->latest('id')
            ->limit(self::RECENT_DELIVERIES)
            ->get();

        $known = $this->providers->has($destination->type);
        $capabilities = $known ? $this->providers->capabilities($destination->type) : null;

        return response()->json([
            'destination' => $this->payload->destination($destination),
            'fields' => $this->payload->schemaFields('destination', $destination->type),
            'capabilities' => [
                'supports_media' => $capabilities !== null && $capabilities->supportsMedia,
                'max_text_length' => $capabilities?->maxTextLength,
                'supports_connection_test' => $capabilities !== null && $capabilities->supportsConnectionTest,
            ],
            'delivery_count' => ContentDelivery::query()->where('destination_id', $destination->getKey())->count(),
            'deliveries' => $deliveries->map(fn (ContentDelivery $delivery): array => [
                'id' => $delivery->getKey(),
                'run_id' => $delivery->broadcastRun?->project_run_id,
                'route' => $delivery->broadcast?->name,
                'title' => $this->text($delivery->contentItem?->title) ?? $this->text($delivery->contentItem?->canonical_url) ?? 'Untitled item',
                'status' => $delivery->status->value,
                'status_label' => $delivery->status->label(),
                'failure' => $delivery->failure?->value,
                'attempted_at' => $delivery->attempted_at->toIso8601String(),
                'finished_at' => $delivery->finished_at?->toIso8601String(),
                'external_reference' => $this->text($delivery->external_reference, 120),
                'error' => $this->text($delivery->error_detail, 300),
                'retry_queued' => $delivery->isRetryQueued(),
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
