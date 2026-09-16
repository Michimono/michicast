<?php

namespace Tests\Unit;

use App\Support\ContentItemHasher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ContentItemHashTest extends TestCase
{
    public function test_the_hash_is_stable_for_the_same_meaningful_fields(): void
    {
        $fields = $this->fields();

        $this->assertSame(
            $this->hasher()->hash($fields),
            $this->hasher()->hash($fields),
        );
    }

    public function test_the_hash_changes_when_meaningful_content_changes(): void
    {
        $original = $this->hasher()->hash($this->fields());
        $changed = $this->hasher()->hash($this->fields(['title' => 'Changed title']));

        $this->assertNotSame($original, $changed);
    }

    public function test_media_order_and_fields_affect_the_hash(): void
    {
        $first = $this->hasher()->hash($this->fields([
            'media' => [
                ['type' => 'image', 'url' => 'https://a.example/1.jpg', 'mime_type' => 'image/jpeg', 'width' => 10, 'height' => 10, 'alt' => 'A'],
                ['type' => 'link', 'url' => 'https://a.example', 'mime_type' => null, 'width' => null, 'height' => null, 'alt' => null],
            ],
        ]));

        $reordered = $this->hasher()->hash($this->fields([
            'media' => [
                ['type' => 'link', 'url' => 'https://a.example', 'mime_type' => null, 'width' => null, 'height' => null, 'alt' => null],
                ['type' => 'image', 'url' => 'https://a.example/1.jpg', 'mime_type' => 'image/jpeg', 'width' => 10, 'height' => 10, 'alt' => 'A'],
            ],
        ]));

        $this->assertNotSame($first, $reordered);
    }

    public function test_published_at_timezone_differences_normalize_to_the_same_hash(): void
    {
        $utc = $this->hasher()->hash($this->fields([
            'published_at' => CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC'),
        ]));

        $madrid = $this->hasher()->hash($this->fields([
            'published_at' => CarbonImmutable::parse('2026-03-07 13:00:00', 'Europe/Madrid'),
        ]));

        $this->assertSame($utc, $madrid);
    }

    public function test_null_and_empty_media_hash_the_same(): void
    {
        $withNull = $this->hasher()->hash($this->fields(['media' => null]));
        $withEmpty = $this->hasher()->hash($this->fields(['media' => []]));

        $this->assertSame($withNull, $withEmpty);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     title: string,
     *     body: string,
     *     author: string,
     *     published_at: CarbonImmutable,
     *     canonical_url: string,
     *     media: list<array<string, mixed>>
     * }
     */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Hello',
            'body' => 'World',
            'author' => 'Ada',
            'published_at' => CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC'),
            'canonical_url' => 'https://example.com/post',
            'media' => [
                [
                    'type' => 'image',
                    'url' => 'https://cdn.example.com/cover.jpg',
                    'mime_type' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 630,
                    'alt' => 'Cover',
                ],
            ],
        ], $overrides);
    }

    private function hasher(): ContentItemHasher
    {
        return new ContentItemHasher;
    }
}
