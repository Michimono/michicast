<?php

namespace Database\Factories;

use App\Enums\ContentItemLifecycle;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\ContentItemHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'external_id' => fake()->uuid(),
            'canonical_url' => fake()->url(),
            'title' => fake()->sentence(),
            'body' => fake()->paragraph(),
            'author' => fake()->name(),
            'published_at' => fake()->dateTimeBetween('-1 year'),
            'discovered_at' => now(),
            'media' => [
                [
                    'type' => 'image',
                    'url' => 'https://cdn.example.com/cover.jpg',
                    'mime_type' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 630,
                    'alt' => 'Cover image',
                ],
            ],
            'metadata' => null,
            'raw_payload' => null,
            'lifecycle' => ContentItemLifecycle::Discovered,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ContentItem $item): void {
            $item->identity_key = ContentItem::identityKey($item->external_id, $item->canonical_url);
            $item->content_hash = (new ContentItemHasher)->hash([
                'title' => $item->title,
                'body' => $item->body,
                'author' => $item->author,
                'published_at' => $item->published_at,
                'canonical_url' => $item->canonical_url,
                'media' => $item->media,
            ]);
        });
    }

    public function withoutExternalId(?string $canonicalUrl = null): static
    {
        return $this->state(fn (): array => [
            'external_id' => null,
            'canonical_url' => $canonicalUrl ?? fake()->url(),
        ]);
    }

    public function forSource(int $sourceId): static
    {
        return $this->state(fn (): array => [
            'source_id' => $sourceId,
        ]);
    }
}
