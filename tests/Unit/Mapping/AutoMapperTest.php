<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\AutoMapper;
use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;
use App\Support\Mapping\TypeCompatibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoMapperTest extends TestCase
{
    #[Test]
    public function it_maps_exact_compatible_names_only_as_set_field(): void
    {
        $source = new ContentSchema(1, [
            new SchemaField('title', 'Title', FieldType::Text),
            new SchemaField('body', 'Body', FieldType::Text),
            new SchemaField('media', 'Images', FieldType::Array, itemSchema: new SchemaField('item', 'Image', FieldType::Media)),
            new SchemaField('categories', 'Categories', FieldType::Array, itemSchema: new SchemaField('item', 'Category', FieldType::Text)),
        ], 'rss', 'RSS');

        $destination = new ContentSchema(1, [
            new SchemaField('title', 'Title', FieldType::Text, required: true),
            new SchemaField('body', 'Body', FieldType::Text),
            new SchemaField('media', 'Media', FieldType::Array, itemSchema: new SchemaField('item', 'Media item', FieldType::Media)),
            new SchemaField('text', 'Message text', FieldType::Text, required: true),
        ], 'custom', 'Custom');

        $definition = (new AutoMapper)->suggest($source, $destination);

        $this->assertSame('set_field', $definition->steps[0]->operation);

        $targets = array_map(fn ($step) => $step->config['field'] ?? null, $definition->steps);

        $this->assertContains('title', $targets);
        $this->assertContains('body', $targets);
        $this->assertContains('media', $targets);
        $this->assertNotContains('text', $targets);

        $titleStep = collect($definition->steps)->first(fn ($s) => ($s->config['field'] ?? null) === 'title');
        $this->assertSame('{{title}}', $titleStep->config['value']);
    }

    #[Test]
    public function array_to_text_is_not_compatible_without_reduction(): void
    {
        $this->assertTrue(TypeCompatibility::arrayNeedsReduction(FieldType::Array, FieldType::Text));
        $this->assertFalse(TypeCompatibility::areCompatible(FieldType::Array, FieldType::Text));
    }
}
