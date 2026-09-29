<?php

namespace App\Support\Mapping;

use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Support\BroadcastTransforms\BroadcastTransformer;
use App\Support\Mapping\Schemas\TelegramDestinationSchema;
use App\Support\PreparedMessage;

/**
 * Chooses the content-mapping engine when a mapping is saved; otherwise falls
 * back to the legacy BroadcastTransformer / template path so existing
 * broadcasts keep working without migration.
 */
class ContentMapper
{
    public function __construct(
        private MappingEngine $engine = new MappingEngine,
        private BroadcastTransformer $transformer = new BroadcastTransformer,
        private LogicalMessageAdapter $adapter = new LogicalMessageAdapter,
        private ProviderSchemas $schemas = new ProviderSchemas,
        private DefaultMappings $defaults = new DefaultMappings,
        private AutoMapper $autoMapper = new AutoMapper,
    ) {}

    public function prepare(Broadcast $broadcast, ContentItem $item, ?string $sourceName = null): PreparedMessage
    {
        $definition = $this->definitionFor($broadcast);

        if ($definition === null) {
            return $this->transformer->transform($item, $broadcast->transform, $sourceName);
        }

        $destinationType = $broadcast->destination->type
            ?? $definition->destinationProvider
            ?? 'recording';

        $destinationSchema = $this->schemas->destinationSchema($destinationType);
        $sourceSchema = $this->schemas->sourceSchema($broadcast->source->type ?? $definition->sourceProvider ?? 'rss');

        if ($destinationSchema === null) {
            return $this->transformer->transform($item, $broadcast->transform, $sourceName);
        }

        $result = $this->engine->evaluate(
            destinationSchema: $destinationSchema,
            definition: $definition,
            sourceData: ContentItemSourceData::from($item),
            sourceSchema: $sourceSchema,
        );

        if (! $result->isValid() && ($result->data['text'] ?? $result->data['body'] ?? null) === null) {
            return $this->transformer->transform($item, $broadcast->transform, $sourceName);
        }

        return $this->adapter->toPreparedMessage($result->data);
    }

    /**
     * Preview using the same engine as production. Never publishes.
     *
     * @param  array<string, mixed>|null  $mappingPayload
     * @param  array<string, mixed>|null  $sourceData
     */
    public function preview(
        Broadcast $broadcast,
        ?array $mappingPayload = null,
        ?array $sourceData = null,
        ?string $throughStepId = null,
    ): MappingResult {
        $sourceType = $broadcast->source->type ?? 'rss';
        $destinationType = $broadcast->destination->type ?? 'telegram';

        $destinationSchema = $this->schemas->destinationSchema($destinationType)
            ?? TelegramDestinationSchema::make();
        $sourceSchema = $this->schemas->sourceSchema($sourceType);

        $definition = $mappingPayload !== null
            ? MappingDefinition::fromArray($mappingPayload)
            : ($this->definitionFor($broadcast) ?? $this->resolvedDefault($sourceType, $destinationType));

        $sourceData ??= $this->schemas->sourceSample($sourceType) ?? [];

        return $this->engine->evaluate(
            destinationSchema: $destinationSchema,
            definition: $definition,
            sourceData: $sourceData,
            throughStepId: $throughStepId,
            sourceSchema: $sourceSchema,
        );
    }

    public function definitionFor(Broadcast $broadcast): ?MappingDefinition
    {
        $raw = $broadcast->mapping;

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return MappingDefinition::fromArray($raw);
    }

    public function resolvedDefault(string $sourceType, string $destinationType): MappingDefinition
    {
        $default = $this->defaults->for($sourceType, $destinationType);

        if ($default !== null) {
            return $default;
        }

        $sourceSchema = $this->schemas->sourceSchema($sourceType);
        $destinationSchema = $this->schemas->destinationSchema($destinationType);

        if ($sourceSchema !== null && $destinationSchema !== null) {
            return $this->autoMapper->suggest($sourceSchema, $destinationSchema);
        }

        return new MappingDefinition(steps: []);
    }

    public function engine(): MappingEngine
    {
        return $this->engine;
    }

    public function schemas(): ProviderSchemas
    {
        return $this->schemas;
    }
}
