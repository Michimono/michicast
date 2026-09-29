<?php

namespace App\Support\Mapping;

/**
 * Best-effort migration of legacy ops into Set field templates.
 * Safe conversions only; otherwise the original step is kept so broadcasts
 * keep working.
 */
final class StepMigrator
{
    public function migrate(MappingDefinition $definition): MappingDefinition
    {
        $steps = [];

        foreach ($definition->steps as $step) {
            $steps[] = $this->migrateStep($step) ?? $step;
        }

        return new MappingDefinition(
            steps: $steps,
            version: $definition->version,
            sourceProvider: $definition->sourceProvider,
            destinationProvider: $definition->destinationProvider,
            sourceSchemaVersion: $definition->sourceSchemaVersion,
            destinationSchemaVersion: $definition->destinationSchemaVersion,
        );
    }

    private function migrateStep(MappingStep $step): ?MappingStep
    {
        return match ($step->operation) {
            'map' => $this->migrateMap($step),
            'add_text' => null, // needs prior field content; keep as-is
            'concatenate' => null,
            default => null,
        };
    }

    private function migrateMap(MappingStep $step): ?MappingStep
    {
        $from = $step->config['from'] ?? null;
        $to = $step->config['to'] ?? null;

        if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
            return null;
        }

        return new MappingStep(
            id: $step->id,
            operation: 'set_field',
            order: $step->order,
            config: [
                'field' => $to,
                'value' => '{{'.$from.'}}',
            ],
        );
    }
}
