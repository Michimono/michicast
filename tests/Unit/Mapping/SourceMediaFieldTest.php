<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\ProviderSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceMediaFieldTest extends TestCase
{
    #[DataProvider('sourceTypes')]
    public function test_every_source_offers_the_same_media_field(string $sourceType): void
    {
        $schemas = new ProviderSchemas;
        $schema = $schemas->sourceSchema($sourceType);

        $this->assertNotNull($schema, $sourceType);

        $field = collect($schema->fields)->firstWhere('name', 'media');

        $this->assertNotNull($field, $sourceType);
        $this->assertSame('Media', $field->label);
        $this->assertSame(FieldType::Array, $field->type);
        $this->assertSame(FieldType::Media, $field->itemSchema?->type);
        $this->assertIsArray($schemas->sourceSample($sourceType)['media'] ?? null);
    }

    #[DataProvider('sourceTypes')]
    public function test_every_default_mapping_attaches_the_media_field(string $sourceType): void
    {
        $defaults = new DefaultMappings;

        foreach (['telegram', 'recording'] as $destinationType) {
            $steps = $defaults->for($sourceType, $destinationType)?->toArray()['steps'] ?? [];
            $media = collect($steps)->firstWhere('config.field', 'media');

            $this->assertSame('{{media}}', $media['config']['value'] ?? null, $sourceType.' to '.$destinationType);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceTypes(): array
    {
        return [
            'rss' => ['rss'],
            'web page' => ['web_page'],
            'reddit' => ['reddit'],
            'x' => ['twitter'],
            'fake' => ['fake'],
        ];
    }
}
