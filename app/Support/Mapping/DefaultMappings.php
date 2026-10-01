<?php

namespace App\Support\Mapping;

use Illuminate\Support\Str;

/**
 * Provider-aware default step sequences for common source → destination pairs.
 * Defaults use Set field templates; legacy multi-step map/add_text/concatenate
 * sequences are no longer emitted for new mappings.
 */
final class DefaultMappings
{
    public function for(string $sourceType, string $destinationType): ?MappingDefinition
    {
        return match ($sourceType.'->'.$destinationType) {
            'rss->telegram', 'fake->telegram' => $this->rssToTelegram($sourceType),
            'twitter->telegram' => $this->twitterToTelegram(),
            'rss->recording', 'fake->recording' => $this->rssToRecording($sourceType),
            'twitter->recording' => $this->twitterToRecording(),
            default => null,
        };
    }

    private function rssToTelegram(string $sourceType): MappingDefinition
    {
        return $this->definition($sourceType, 'telegram', [
            ['set_field', [
                'field' => 'text',
                'value' => "{{title}}\n\n{{body}}\n\nRead more: {{link}}",
            ]],
            ['set_field', [
                'field' => 'media',
                'value' => '{{media}}',
            ]],
        ]);
    }

    private function twitterToTelegram(): MappingDefinition
    {
        return $this->definition('twitter', 'telegram', [
            ['set_field', [
                'field' => 'text',
                'value' => "{{body}}\n\n{{link}}",
            ]],
            ['set_field', [
                'field' => 'media',
                'value' => '{{media}}',
            ]],
        ]);
    }

    private function rssToRecording(string $sourceType): MappingDefinition
    {
        return $this->definition($sourceType, 'recording', [
            ['set_field', [
                'field' => 'text',
                'value' => "{{title}}\n\n{{body}}",
            ]],
            ['set_field', [
                'field' => 'link',
                'value' => '{{link}}',
            ]],
            ['set_field', [
                'field' => 'media',
                'value' => '{{media}}',
            ]],
        ]);
    }

    private function twitterToRecording(): MappingDefinition
    {
        return $this->definition('twitter', 'recording', [
            ['set_field', [
                'field' => 'text',
                'value' => '{{body}}',
            ]],
            ['set_field', [
                'field' => 'link',
                'value' => '{{link}}',
            ]],
            ['set_field', [
                'field' => 'media',
                'value' => '{{media}}',
            ]],
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
