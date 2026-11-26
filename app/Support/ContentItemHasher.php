<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class ContentItemHasher
{
    /**
     * Hash the fields that represent the meaningful content of an item.
     * Identity, lifecycle, metadata and raw payloads are intentionally excluded.
     *
     * @param  array{
     *     title?: string|null,
     *     body?: string|null,
     *     author?: string|null,
     *     published_at?: DateTimeInterface|string|null,
     *     canonical_url?: string|null,
     *     media?: list<array<array-key, mixed>>|null
     * }  $fields
     */
    public function hash(array $fields): string
    {
        $payload = [
            'author' => $fields['author'] ?? null,
            'body' => $fields['body'] ?? null,
            'canonical_url' => $fields['canonical_url'] ?? null,
            'media' => $this->normalizeMedia($fields['media'] ?? null),
            'published_at' => $this->normalizePublishedAt($fields['published_at'] ?? null),
            'title' => $fields['title'] ?? null,
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The item's media in the shared shape (see MediaList), so the hash and the
     * stored list always agree.
     *
     * @param  list<array<array-key, mixed>>|null  $media
     * @return list<array{
     *     alt: string|null,
     *     height: int|null,
     *     mime_type: string|null,
     *     type: string,
     *     url: string,
     *     width: int|null
     * }>
     */
    public function normalizeMedia(?array $media): array
    {
        return MediaList::normalize($media);
    }

    private function normalizePublishedAt(DateTimeInterface|string|null $publishedAt): ?string
    {
        if ($publishedAt === null || $publishedAt === '') {
            return null;
        }

        try {
            $instant = $publishedAt instanceof DateTimeInterface
                ? CarbonImmutable::instance($publishedAt)
                : CarbonImmutable::parse($publishedAt);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('published_at must be a parseable datetime.', previous: $exception);
        }

        return $instant->utc()->toIso8601String();
    }
}
