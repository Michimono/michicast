<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBroadcastMappingPreviewRequest;
use App\Http\Requests\UpdateBroadcastMappingRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Support\Mapping\AutoMapper;
use App\Support\Mapping\ContentMapper;
use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\MappingDefinition;
use App\Support\Mapping\MappingResult;
use App\Support\Mapping\OperationRegistry;
use App\Support\Mapping\Operations\FormatDate;
use App\Support\Mapping\ProviderSchemas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Persists and previews step-based content mappings for a broadcast pipeline.
 * The editor itself lives inline on the broadcast overview; these endpoints
 * save and evaluate without a separate mapping page.
 */
class BroadcastMappingController extends Controller
{
    public function __construct(
        private ProviderSchemas $schemas,
        private ContentMapper $mapper,
        private AutoMapper $autoMapper,
        private DefaultMappings $defaults,
        private OperationRegistry $operations = new OperationRegistry,
    ) {}

    public function update(UpdateBroadcastMappingRequest $request, Organization $organization, Broadcast $broadcast): RedirectResponse|JsonResponse
    {
        $mapping = $request->mapping();

        if ($mapping !== null) {
            $preview = $this->mapper->preview($broadcast, $mapping, $request->sourceData());

            if (! $preview->isValid()) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'ok' => false,
                        'errors' => array_map(
                            static fn ($error) => $error->toArray(),
                            $preview->errors,
                        ),
                        'message' => 'Fix the mapping before saving.',
                    ], 422);
                }

                return redirect()
                    ->route('broadcasts.show', $broadcast->project)
                    ->withInput()
                    ->withErrors($this->errorBag($preview))
                    ->with('error', 'Fix the mapping before saving.');
            }
        }

        $broadcast->update([
            'mapping' => $mapping,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Mapping saved.',
                'mapping' => $mapping,
            ]);
        }

        return redirect()
            ->route('broadcasts.show', $broadcast->project)
            ->with('success', 'The mapping has been saved.');
    }

    public function preview(StoreBroadcastMappingPreviewRequest $request, Organization $organization, Broadcast $broadcast): JsonResponse
    {
        $broadcast->loadMissing(['source', 'destination']);

        $mapping = $request->mapping();
        $sourceData = $request->sourceData();
        $through = $request->throughStepId();

        $result = $this->mapper->preview($broadcast, $mapping, $sourceData, $through);

        return response()->json([
            'ok' => $result->isValid() || $through !== null,
            'data' => $result->data,
            'errors' => array_map(
                static fn ($error) => $error->toArray(),
                $result->errors,
            ),
            'human' => $this->humanPreview($result->data, $broadcast->destination->type ?? 'telegram'),
        ]);
    }

    public function sample(Organization $organization, Broadcast $broadcast): JsonResponse
    {
        Gate::authorize('preview', $broadcast);

        $broadcast->loadMissing(['source']);
        $type = $broadcast->source->type ?? 'rss';
        $sample = $this->schemas->sourceSample($type) ?? [];

        return response()->json([
            'sample' => $sample,
            'field_count' => count($sample),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function editorPayload(Broadcast $broadcast): array
    {
        $broadcast->loadMissing(['source', 'destination', 'project']);

        $sourceType = $broadcast->source?->type;
        $destinationType = $broadcast->destination?->type;

        if ($sourceType === null || $destinationType === null) {
            return [
                'ready' => false,
            ];
        }

        $sourceSchema = $this->schemas->sourceSchema($sourceType);
        $destinationSchema = $this->schemas->destinationSchema($destinationType);
        $sample = $this->schemas->sourceSample($sourceType) ?? [];
        $stored = $broadcast->mapping;
        $hasStored = is_array($stored) && $stored !== [];
        $defaultDefinition = $this->mapper->resolvedDefault($sourceType, $destinationType);
        $autoDefinition = ($sourceSchema !== null && $destinationSchema !== null)
            ? $this->autoMapper->suggest($sourceSchema, $destinationSchema)
            : new MappingDefinition(steps: []);
        $providerDefault = $this->defaults->for($sourceType, $destinationType);

        $active = $hasStored
            ? MappingDefinition::fromArray($stored)
            : $defaultDefinition;

        $preview = $this->mapper->preview($broadcast, $active->toArray(), $sample);

        $stepCards = [];
        foreach ($active->steps as $step) {
            $op = $this->operations->has($step->operation)
                ? $this->operations->get($step->operation)
                : null;
            $stepCards[] = [
                'id' => $step->id,
                'operation' => $step->operation,
                'order' => $step->order,
                'config' => $step->config,
                'label' => $op?->label() ?? $step->operation,
                'summary' => $op && $sourceSchema && $destinationSchema
                    ? $op->summarize($step->config, $sourceSchema, $destinationSchema)
                    : '',
            ];
        }

        return [
            'ready' => true,
            'broadcast_id' => $broadcast->getKey(),
            'source' => [
                'name' => $broadcast->source->name,
                'type' => $sourceType,
                'type_label' => ($sourceSchema !== null && $sourceSchema->label !== '') ? $sourceSchema->label : $sourceType,
                'schema' => $sourceSchema?->toArray(),
                'sample' => $sample,
                'field_count' => $sourceSchema?->fieldCount() ?? count($sample),
            ],
            'destination' => [
                'name' => $broadcast->destination->name,
                'type' => $destinationType,
                'type_label' => ($destinationSchema !== null && $destinationSchema->label !== '') ? $destinationSchema->label : $destinationType,
                'schema' => $destinationSchema?->toArray(),
                'parse_mode' => is_string(data_get($broadcast->destination->config, 'parse_mode'))
                    ? data_get($broadcast->destination->config, 'parse_mode')
                    : null,
            ],
            'mapping' => $active->toArray(),
            'steps' => $stepCards,
            'has_stored_mapping' => $hasStored,
            'default_mapping' => $defaultDefinition->toArray(),
            'provider_default' => $providerDefault?->toArray(),
            'auto_mapping' => $autoDefinition->toArray(),
            'auto_match_count' => count($autoDefinition->steps),
            'proposed_count' => count($active->steps),
            'operations' => $this->operations->catalog(),
            'date_presets' => FormatDate::PRESETS,
            'preview' => [
                'data' => $preview->data,
                'errors' => array_map(static fn ($e) => $e->toArray(), $preview->errors),
                'human' => $this->humanPreview($preview->data, $destinationType),
            ],
            'urls' => [
                'update' => route('broadcast-mappings.update', $broadcast),
                'preview' => route('broadcast-mapping-previews.store', $broadcast),
                'sample' => route('broadcast-mapping-samples.store', $broadcast),
            ],
            'csrf' => csrf_token(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{text?: string, media?: list<mixed>, link?: string|null}
     */
    private function humanPreview(array $data, string $destinationType): array
    {
        return [
            'text' => isset($data['text']) && is_scalar($data['text']) ? (string) $data['text'] : '',
            'media' => isset($data['media']) && is_array($data['media']) ? array_values($data['media']) : [],
            'link' => isset($data['link']) && is_string($data['link']) ? $data['link'] : null,
            'destination_type' => $destinationType,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function errorBag(MappingResult $preview): array
    {
        $bag = [];

        foreach ($preview->errors as $index => $error) {
            $key = $error->element !== null
                ? 'mapping.steps.'.$error->element
                : 'mapping.'.$error->field;
            $bag[$key !== 'mapping.' ? $key : 'mapping.'.$index] = $error->message;
        }

        return $bag;
    }
}
