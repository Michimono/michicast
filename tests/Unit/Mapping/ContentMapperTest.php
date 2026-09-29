<?php

namespace Tests\Unit\Mapping;

use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Support\Mapping\ContentMapper;
use App\Support\Mapping\DefaultMappings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentMapperTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uses_the_mapping_engine_when_mapping_is_saved(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake']);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording']);
        $mapping = (new DefaultMappings)->for('fake', 'recording')?->toArray();

        $broadcast = Broadcast::factory()->for($project)->create([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'mapping' => $mapping,
        ]);

        $item = new ContentItem;
        $item->forceFill([
            'title' => 'Hello',
            'body' => 'World',
            'canonical_url' => 'https://example.com',
            'media' => [],
        ]);

        $message = (new ContentMapper)->prepare($broadcast->load(['source', 'destination']), $item);

        $this->assertStringContainsString('Hello', $message->body);
        $this->assertStringContainsString('World', $message->body);
        $this->assertSame('https://example.com', $message->link);
    }

    #[Test]
    public function it_falls_back_to_transform_when_mapping_is_null(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake']);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording']);

        $broadcast = Broadcast::factory()->for($project)->create([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'mapping' => null,
            'transform' => ['template' => 'T:{{title}}'],
        ]);

        $item = new ContentItem;
        $item->forceFill(['title' => 'Fallback', 'body' => '', 'media' => []]);

        $message = (new ContentMapper)->prepare($broadcast->load(['source', 'destination']), $item, 'Feed');

        $this->assertSame('T:Fallback', $message->body);
    }
}
