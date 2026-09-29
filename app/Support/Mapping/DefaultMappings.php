<?php

namespace App\Support\Mapping;

use Illuminate\Support\Str;

/**
 * Provider-aware default step sequences for common source → destination pairs.
 */
final class DefaultMappings
{
    public function for(string $sourceType, string $destinationType): ?MappingDefinition
    {
        return match ($sourceType.'->'.$destinationType) {
            'rss->telegram', 'fake->telegram' => $this->rssToTelegram($sourceType),
            'rss->recording', 'fake->recording' => $this->rssToRecording($sourceType),
            default => null,
        };
    }

    private function rssToTelegram(string $sourceType): MappingDefinition
    {
        return $this->definition($sourceType, 'telegram', [
            ['map', ['from' => 'title', 'to' => 'text']],
            ['add_text', ['text' => "\n\n", 'to' => 'text']],
            ['concatenate', ['value' => 'body', 'to' => 'text']],
            ['add_text', ['text' => "\n\nRead more: ", 'to' => 'text']],
            ['concatenate', ['value' => 'link', 'to' => 'text']],
            ['map', ['from' => 'media', 'to' => 'media']],
        ]);
    }

    private function rssToRecording(string $sourceType): MappingDefinition
    {
        return $this->definition($sourceType, 'recording', [
            ['map', ['from' => 'title', 'to' => 'text']],
            ['add_text', ['text' => "\n\n", 'to' => 'text']],
            ['concatenate', ['value' => 'body', 'to' => 'text']],
            ['map', ['from' => 'link', 'to' => 'link']],
            ['map', ['from' => 'media', 'to' => 'media']],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $rawSteps
     */
    private function definition(string $sourceType, string $destinationType, array $rawSteps): MappingDefinition
    {
        $steps = [];

        foreach ($rawSteps as $index => [$operation, $config]) {
            $steps[] = new MappingStep(
                id: (string) Str::uuid(),
                operation: $operation,
                order: $index,
                config: $config,
            );
        }

        return new MappingDefinition(
            steps: $steps,
            sourceProvider: $sourceType,
            destinationProvider: $destinationType,
            sourceSchemaVersion: 1,
            destinationSchemaVersion: 1,
        );
    }
}
