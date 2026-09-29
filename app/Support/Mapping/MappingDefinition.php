<?php

namespace App\Support\Mapping;

/**
 * Declarative ordered pipeline of mapping steps.
 */
readonly class MappingDefinition
{
    /**
     * @param  list<MappingStep>  $steps
     */
    public function __construct(
        public array $steps = [],
        public int $version = 1,
        public ?string $sourceProvider = null,
        public ?string $destinationProvider = null,
        public ?int $sourceSchemaVersion = null,
        public ?int $destinationSchemaVersion = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (array_values($data['steps'] ?? []) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $parsed = MappingStep::fromArray($step, isset($step['order']) ? (int) $step['order'] : $index);

            if ($parsed->operation === '') {
                continue;
            }

            $steps[] = $parsed;
        }

        usort($steps, static fn (MappingStep $a, MappingStep $b): int => $a->order <=> $b->order);

        $normalized = [];
        foreach ($steps as $index => $step) {
            $normalized[] = new MappingStep($step->id, $step->operation, $index, $step->config);
        }

        return new self(
            steps: $normalized,
            version: (int) ($data['version'] ?? 1),
            sourceProvider: isset($data['source_provider']) && is_string($data['source_provider'])
                ? $data['source_provider']
                : null,
            destinationProvider: isset($data['destination_provider']) && is_string($data['destination_provider'])
                ? $data['destination_provider']
                : null,
            sourceSchemaVersion: isset($data['source_schema_version']) ? (int) $data['source_schema_version'] : null,
            destinationSchemaVersion: isset($data['destination_schema_version']) ? (int) $data['destination_schema_version'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'version' => $this->version,
            'steps' => array_map(
                static fn (MappingStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];

        if ($this->sourceProvider !== null) {
            $payload['source_provider'] = $this->sourceProvider;
        }

        if ($this->destinationProvider !== null) {
            $payload['destination_provider'] = $this->destinationProvider;
        }

        if ($this->sourceSchemaVersion !== null) {
            $payload['source_schema_version'] = $this->sourceSchemaVersion;
        }

        if ($this->destinationSchemaVersion !== null) {
            $payload['destination_schema_version'] = $this->destinationSchemaVersion;
        }

        return $payload;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * @return list<MappingStep>
     */
    public function stepsUpTo(?string $throughStepId): array
    {
        if ($throughStepId === null) {
            return $this->steps;
        }

        $subset = [];

        foreach ($this->steps as $step) {
            $subset[] = $step;

            if ($step->id === $throughStepId) {
                break;
            }
        }

        return $subset;
    }
}
