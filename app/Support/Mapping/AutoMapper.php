<?php

namespace App\Support\Mapping;

use Illuminate\Support\Str;

/**
 * Suggests high-confidence step mappings: exact name + compatible types.
 */
class AutoMapper
{
    public function suggest(ContentSchema $source, ContentSchema $destination): MappingDefinition
    {
        $steps = [];
        $order = 0;

        foreach ($destination->fields as $destField) {
            $sourceField = $source->field($destField->name);

            if ($sourceField === null) {
                continue;
            }

            if (! TypeCompatibility::fieldsAreCompatible($sourceField, $destField)) {
                continue;
            }

            $steps[] = new MappingStep(
                id: (string) Str::uuid(),
                operation: 'map',
                order: $order++,
                config: [
                    'from' => $sourceField->name,
                    'to' => $destField->name,
                ],
            );
        }

        return new MappingDefinition(
            steps: $steps,
            sourceProvider: $source->providerType !== '' ? $source->providerType : null,
            destinationProvider: $destination->providerType !== '' ? $destination->providerType : null,
            sourceSchemaVersion: $source->version,
            destinationSchemaVersion: $destination->version,
        );
    }

    public function matchCount(ContentSchema $source, ContentSchema $destination): int
    {
        return count($this->suggest($source, $destination)->steps);
    }
}
