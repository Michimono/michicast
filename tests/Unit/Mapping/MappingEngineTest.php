<?php

namespace Tests\Unit\Mapping;

use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\MappingDefinition;
use App\Support\Mapping\MappingEngine;
use App\Support\Mapping\MappingStep;
use App\Support\Mapping\Schemas\RssSourceSchema;
use App\Support\Mapping\Schemas\TelegramDestinationSchema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MappingEngineTest extends TestCase
{
    #[Test]
    public function it_evaluates_ordered_steps_into_destination_data(): void
    {
        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'map', 0, ['from' => 'title', 'to' => 'text']),
            new MappingStep('2', 'add_text', 1, ['text' => "\n\n", 'to' => 'text']),
            new MappingStep('3', 'concatenate', 2, ['value' => 'body', 'to' => 'text']),
            new MappingStep('4', 'add_text', 3, ['text' => "\n\nRead more: ", 'to' => 'text']),
            new MappingStep('5', 'concatenate', 4, ['value' => 'link', 'to' => 'text']),
            new MappingStep('6', 'map', 5, ['from' => 'media', 'to' => 'media']),
        ]);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            RssSourceSchema::sample(),
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertTrue($result->isValid());
        $this->assertStringContainsString('New Pokémon expansion announced', $result->data['text']);
        $this->assertStringContainsString('Read more: ', $result->data['text']);
        $this->assertStringContainsString('https://example.com/posts/sample', $result->data['text']);
        $this->assertCount(1, $result->data['media']);
    }

    #[Test]
    public function it_can_preview_intermediate_results_up_to_a_step(): void
    {
        $definition = new MappingDefinition(steps: [
            new MappingStep('a', 'map', 0, ['from' => 'title', 'to' => 'text']),
            new MappingStep('b', 'add_text', 1, ['text' => "\n", 'to' => 'text']),
            new MappingStep('c', 'concatenate', 2, ['value' => 'body', 'to' => 'text']),
        ]);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            RssSourceSchema::sample(),
            throughStepId: 'a',
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertSame('New Pokémon expansion announced', $result->data['text']);
    }

    #[Test]
    public function default_rss_to_telegram_mapping_produces_required_text(): void
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
        $this->assertNotSame('', $result->data['text']);
    }

    #[Test]
    public function trim_and_replace_mutate_destination_fields(): void
    {
        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'map', 0, ['from' => 'title', 'to' => 'text']),
            new MappingStep('2', 'replace', 1, ['in' => 'text', 'find' => 'Pokémon', 'replace' => 'Pokemon']),
            new MappingStep('3', 'trim', 2, ['field' => 'text', 'max_length' => 10]),
        ]);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            RssSourceSchema::sample(),
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertSame('New Pokemo', $result->data['text']);
    }

    #[Test]
    public function join_turns_an_array_into_text(): void
    {
        $definition = new MappingDefinition(steps: [
            new MappingStep('1', 'join', 0, ['field' => 'categories', 'separator' => ', ', 'to' => 'text']),
        ]);

        $result = (new MappingEngine)->evaluate(
            TelegramDestinationSchema::make(),
            $definition,
            RssSourceSchema::sample(),
            sourceSchema: RssSourceSchema::make(),
        );

        $this->assertSame('news, pocket', $result->data['text']);
    }

    #[Test]
    public function mapping_definition_round_trips_through_array(): void
    {
        $original = (new DefaultMappings)->for('rss', 'telegram');
        $restored = MappingDefinition::fromArray($original->toArray());

        $this->assertCount(count($original->steps), $restored->steps);
        $this->assertSame($original->steps[0]->operation, $restored->steps[0]->operation);
    }

    #[Test]
    public function preview_and_production_share_engine_results(): void
    {
        $definition = (new DefaultMappings)->for('rss', 'telegram');
        $engine = new MappingEngine;
        $source = RssSourceSchema::sample();

        $a = $engine->evaluate(TelegramDestinationSchema::make(), $definition, $source, sourceSchema: RssSourceSchema::make());
        $b = $engine->evaluate(TelegramDestinationSchema::make(), $definition, $source, sourceSchema: RssSourceSchema::make());

        $this->assertSame($a->data, $b->data);
        $this->assertSame(
            array_map(fn ($e) => $e->toArray(), $a->errors),
            array_map(fn ($e) => $e->toArray(), $b->errors),
        );
    }
}
