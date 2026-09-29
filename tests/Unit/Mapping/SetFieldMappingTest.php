<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\MappingDefinition;
use App\Support\Mapping\MappingEngine;
use App\Support\Mapping\MappingStep;
use App\Support\Mapping\SchemaField;
use App\Support\Mapping\Schemas\RssSourceSchema;
use App\Support\Mapping\Schemas\TelegramDestinationSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetFieldMappingTest extends TestCase
{
    private function schemaWith(array $fields): ContentSchema
    {
        return new ContentSchema(1, $fields, 'test', 'Test');
    }

    #[Test]
    public function sequential_overwrite_feeds_next_step_message(): void
    {
        // Source title=Hello
        // Step1 title = "Prefix: {{title}}"
        // Step2 message = "{{title}}"
        // → message = "Prefix: Hello"
        $destination = $this->schemaWith([
            new SchemaField('title', 'Title', FieldType::Text),
            new SchemaField('message', 'Message', FieldType::Text, required: true),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'set_field', 0, ['field' => 'title', 'value' => 'Prefix: {{title}}']),
            new MappingStep('2', 'set_field', 1, ['field' => 'message', 'value' => '{{title}}']),
        ]);

        $result = (new MappingEngine)->evaluate(
            $destination,
            $definition,
            ['title' => 'Hello'],
            sourceSchema: $this->schemaWith([new SchemaField('title', 'Title', FieldType::Text)]),
        );

        $this->assertSame('Prefix: Hello', $result->data['message']);
        $this->assertSame('Prefix: Hello', $result->data['title']);
    }

    #[Test]
    public function sequential_overwrite_of_same_field(): void
    {
        // Step1 title = "Prefix: {{title}}"
        // Step2 title = "{{title}}!"
        // → title = "Prefix: Hello!"
        $destination = $this->schemaWith([
            new SchemaField('title', 'Title', FieldType::Text, required: true),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'set_field', 0, ['field' => 'title', 'value' => 'Prefix: {{title}}']),
            new MappingStep('2', 'set_field', 1, ['field' => 'title', 'value' => '{{title}}!']),
        ]);

        $result = (new MappingEngine)->evaluate(
            $destination,
            $definition,
            ['title' => 'Hello'],
            sourceSchema: $this->schemaWith([new SchemaField('title', 'Title', FieldType::Text)]),
        );

        $this->assertSame('Prefix: Hello!', $result->data['title']);
    }

    #[Test]
    public function intermediate_vars_and_destination_projection(): void
    {
        $destination = $this->schemaWith([
            new SchemaField('text', 'Text', FieldType::Text, required: true),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'set_field', 0, ['field' => 'greeting', 'value' => 'Hi {{title}}']),
            new MappingStep('2', 'set_field', 1, ['field' => 'text', 'value' => '{{greeting}} — {{body}}']),
        ]);

        $result = (new MappingEngine)->evaluate(
            $destination,
            $definition,
            ['title' => 'Ada', 'body' => 'Hello'],
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertSame('Hi Ada — Hello', $result->data['text']);
        $this->assertArrayNotHasKey('greeting', $result->data);
        $this->assertArrayNotHasKey('title', $result->data);
    }

    #[Test]
    public function order_matters_and_last_write_wins(): void
    {
        $destination = $this->schemaWith([
            new SchemaField('text', 'Text', FieldType::Text, required: true),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('a', 'set_field', 0, ['field' => 'text', 'value' => 'first']),
            new MappingStep('b', 'set_field', 1, ['field' => 'text', 'value' => 'second']),
        ]);

        $result = (new MappingEngine)->evaluate($destination, $definition, []);

        $this->assertSame('second', $result->data['text']);
    }

    #[Test]
    public function missing_placeholder_assigns_empty_and_reports_error(): void
    {
        $destination = $this->schemaWith([
            new SchemaField('text', 'Text', FieldType::Text, required: true),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'set_field', 0, ['field' => 'text', 'value' => 'X{{missing}}Y']),
        ]);

        $result = (new MappingEngine)->evaluate($destination, $definition, ['title' => 't']);

        $this->assertSame('XY', $result->data['text']);
        $this->assertNotEmpty($result->errors);
        $this->assertSame('missing', $result->errors[0]->type);
    }

    #[Test]
    public function required_destination_validation(): void
    {
        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            new MappingDefinition(steps: []),
            RssSourceSchema::sample(),
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('required', $result->errors[0]->type);
    }

    #[Test]
    public function legacy_map_steps_still_evaluate(): void
    {
        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'map', 0, ['from' => 'title', 'to' => 'text']),
            new MappingStep('2', 'add_text', 1, ['text' => '!', 'to' => 'text']),
        ]);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            ['title' => 'Hello', 'body' => '', 'link' => '', 'media' => []],
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertSame('Hello!', $result->data['text']);
    }

    #[Test]
    public function default_rss_telegram_uses_set_field(): void
    {
        $definition = (new DefaultMappings)->for('rss', 'telegram');
        $this->assertNotNull($definition);
        $this->assertSame('set_field', $definition->steps[0]->operation);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            RssSourceSchema::sample(),
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertTrue($result->isValid());
        $this->assertStringContainsString('New Pokémon expansion announced', $result->data['text']);
        $this->assertStringContainsString('Read more: ', $result->data['text']);
        $this->assertCount(1, $result->data['media']);
    }

    #[Test]
    public function through_step_preview_sees_context_mutations(): void
    {
        $destination = $this->schemaWith([
            new SchemaField('title', 'Title', FieldType::Text),
            new SchemaField('message', 'Message', FieldType::Text),
        ]);

        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'set_field', 0, ['field' => 'title', 'value' => 'Prefix: {{title}}']),
            new MappingStep('2', 'set_field', 1, ['field' => 'message', 'value' => '{{title}}']),
        ]);

        $afterFirst = (new MappingEngine)->evaluate(
            $destination,
            $definition,
            ['title' => 'Hello'],
            throughStepId: '1',
        );

        $this->assertSame('Prefix: Hello', $afterFirst->data['title']);
    }
}
