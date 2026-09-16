<?php

namespace Tests\Feature;

use App\Enums\ContentItemLifecycle;
use App\Exceptions\ContentItemIdentityException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\ContentItemHasher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_content_item_can_be_persisted_with_the_full_canonical_shape(): void
    {
        $project = Project::factory()->create();
        $media = [
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/cover.jpg',
                'mime_type' => 'image/jpeg',
                'width' => 1200,
                'height' => 630,
                'alt' => 'Cover image',
            ],
        ];

        $item = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 42,
            'external_id' => 'post-1',
            'canonical_url' => 'https://example.com/posts/1',
            'title' => 'Hello',
            'body' => 'World',
            'author' => 'Ada',
            'published_at' => '2026-03-07T12:00:00Z',
            'media' => $media,
            'metadata' => ['provider' => 'rss', 'guid' => 'post-1'],
            'raw_payload' => ['title' => 'Hello', 'description' => 'World'],
        ]);

        $this->assertTrue($item->wasRecentlyCreated);
        $this->assertSame(ContentItemLifecycle::Discovered, $item->lifecycle);
        $this->assertSame('ext:post-1', $item->identity_key);
        $this->assertEquals($media, $item->fresh()->media);
        $this->assertSame(['provider' => 'rss', 'guid' => 'post-1'], $item->metadata);
        $this->assertSame(['title' => 'Hello', 'description' => 'World'], $item->raw_payload);
        $this->assertNotEmpty($item->content_hash);
        $this->assertTrue($project->contentItems()->whereKey($item)->exists());
    }

    public function test_re_recording_the_same_external_id_for_a_source_does_not_create_a_second_row(): void
    {
        $project = Project::factory()->create();

        $first = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 7,
            'external_id' => 'same-id',
            'canonical_url' => 'https://example.com/a',
            'title' => 'Original',
            'body' => 'Body',
        ]);

        $second = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 7,
            'external_id' => 'same-id',
            'canonical_url' => 'https://example.com/a',
            'title' => 'Original',
            'body' => 'Body',
        ]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ContentItem::query()->where('source_id', 7)->count());
    }

    public function test_a_hash_change_updates_the_existing_row_for_the_same_identity(): void
    {
        $project = Project::factory()->create();

        $first = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 7,
            'external_id' => 'same-id',
            'title' => 'Original',
            'body' => 'Body',
        ]);

        $updated = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 7,
            'external_id' => 'same-id',
            'title' => 'Revised',
            'body' => 'Body',
        ]);

        $this->assertTrue($first->is($updated));
        $this->assertSame(1, ContentItem::query()->where('source_id', 7)->count());
        $this->assertSame('Revised', $updated->fresh()->title);
        $this->assertNotSame($first->content_hash, $updated->content_hash);
    }

    public function test_without_an_external_id_the_canonical_url_provides_identity(): void
    {
        $project = Project::factory()->create();

        $first = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 9,
            'canonical_url' => 'https://example.com/no-id',
            'title' => 'URL item',
        ]);

        $second = ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 9,
            'canonical_url' => 'https://example.com/no-id',
            'title' => 'URL item',
        ]);

        $this->assertSame('url:https://example.com/no-id', $first->identity_key);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, ContentItem::query()->where('source_id', 9)->count());
    }

    public function test_media_round_trips_without_loss(): void
    {
        $media = [
            [
                'type' => 'image',
                'url' => 'https://cdn.example.com/a.png',
                'mime_type' => 'image/png',
                'width' => 100,
                'height' => 80,
                'alt' => 'Diagram',
            ],
            [
                'type' => 'link',
                'url' => 'https://example.com',
                'mime_type' => null,
                'width' => null,
                'height' => null,
                'alt' => null,
            ],
        ];

        $item = ContentItem::factory()->create(['media' => $media]);

        $this->assertEquals($media, $item->fresh()->media);
    }

    public function test_the_unique_identity_constraint_is_enforced_at_the_database_level(): void
    {
        $project = Project::factory()->create();
        $identityKey = ContentItem::identityKey('dup', null);
        $hash = (new ContentItemHasher)->hash(['title' => 'A']);

        (new ContentItem)->forceFill([
            'project_id' => $project->id,
            'source_id' => 3,
            'external_id' => 'dup',
            'identity_key' => $identityKey,
            'title' => 'A',
            'discovered_at' => now(),
            'content_hash' => $hash,
            'lifecycle' => ContentItemLifecycle::Discovered,
        ])->save();

        $this->expectException(QueryException::class);

        (new ContentItem)->forceFill([
            'project_id' => $project->id,
            'source_id' => 3,
            'external_id' => 'dup',
            'identity_key' => $identityKey,
            'title' => 'B',
            'discovered_at' => now(),
            'content_hash' => (new ContentItemHasher)->hash(['title' => 'B']),
            'lifecycle' => ContentItemLifecycle::Discovered,
        ])->save();
    }

    public function test_recording_without_external_id_or_canonical_url_is_rejected(): void
    {
        $project = Project::factory()->create();

        $this->expectException(ContentItemIdentityException::class);

        ContentItem::record([
            'project_id' => $project->id,
            'source_id' => 1,
            'title' => 'Orphan',
        ]);
    }
}
