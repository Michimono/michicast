<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Support\Mapping\ContentMapper;
use App\Support\Mapping\DefaultMappings;
use App\Support\ProjectRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentMappingPipelineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function runner_preview_uses_mapping_engine_when_mapping_is_set(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create([
            'type' => 'fake',
            'config' => [
                'feed_url' => 'https://example.com/feed',
                'items' => [
                    [
                        'external_id' => 'item-1',
                        'title' => 'Hello',
                        'body' => 'World',
                        'canonical_url' => 'https://example.com/1',
                        'published_at' => now()->toIso8601String(),
                    ],
                ],
            ],
        ]);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording']);

        $broadcast = Broadcast::factory()->for($project)->create([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'mapping' => (new DefaultMappings)->for('fake', 'recording')?->toArray(),
            'enabled' => true,
        ]);

        $preview = app(ProjectRunner::class)->preview($broadcast->load(['source', 'destination']));

        $this->assertGreaterThan(0, $preview->found);
    }

    #[Test]
    public function content_mapper_and_preview_share_engine_output(): void
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
        ])->load(['source', 'destination']);

        $mapper = new ContentMapper;
        $item = new ContentItem;
        $item->forceFill([
            'title' => 'Shared',
            'body' => 'Engine',
            'canonical_url' => 'https://example.com/shared',
            'media' => [],
        ]);

        $prepared = $mapper->prepare($broadcast, $item);
        $preview = $mapper->preview($broadcast, $mapping, [
            'title' => 'Shared',
            'body' => 'Engine',
            'link' => 'https://example.com/shared',
            'media' => [],
            'categories' => [],
            'published_at' => null,
            'author' => null,
        ]);

        $this->assertSame($preview->data['text'] ?? null, $prepared->body);
    }
}
