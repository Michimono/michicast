<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a Reddit public listing JSON body into normalized post entries.
 *
 * Removed or deleted posts still appear in listings; this parser skips them so
 * transforms and destinations only see posts with usable content.
 */
class RedditListingParser
{
    /**
     * @return list<array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     is_self: bool,
     *     linked_url: string|null,
     *     raw: array<string, mixed>
     * }>
     */
    public function parse(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException(
                'The Reddit listing response could not be parsed.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new SourceFetchFailedException('The Reddit listing response could not be parsed.');
        }

        $children = data_get($decoded, 'data.children');

        if (! is_array($children)) {
            throw new SourceFetchFailedException(
                'The Reddit listing response had an unexpected shape.',
            );
        }

        $entries = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $normalized = $this->normalizeChild($child);

            if ($normalized !== null) {
                $entries[] = $normalized;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $child
     * @return array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     is_self: bool,
     *     linked_url: string|null,
     *     raw: array<string, mixed>
     * }|null
     */
    private function normalizeChild(array $child): ?array
    {
        $post = $child['data'] ?? null;

        if (! is_array($post)) {
            return null;
        }

        if ($this->shouldSkip($post)) {
            return null;
        }

        $externalId = $this->stringOrNull($post['id'] ?? null)
            ?? $this->stringOrNull($post['name'] ?? null);

        if ($externalId === null) {
            return null;
        }

        // Reddit sometimes prefixes the fullname as t3_<id>.
        if (str_starts_with($externalId, 't3_')) {
            $externalId = substr($externalId, 3);
        }

        $title = $this->stringOrNull($post['title'] ?? null) ?? '';
        $isSelf = (bool) ($post['is_self'] ?? false);
        $selfText = $this->stringOrNull($post['selftext'] ?? null);
        $linkedUrl = $this->stringOrNull($post['url'] ?? null);
        $permalink = $this->stringOrNull($post['permalink'] ?? null);
        $author = $this->stringOrNull($post['author'] ?? null);

        $canonicalUrl = $permalink !== null
            ? 'https://www.reddit.com'.$permalink
            : ($linkedUrl ?? 'https://www.reddit.com/comments/'.$externalId);

        // Self posts expose the text body; link posts expose the outbound URL so
        // transforms have something sensible either way.
        $body = $isSelf ? $selfText : $linkedUrl;

        return [
            'external_id' => $externalId,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'body' => $body,
            'author' => $author,
            'published_at' => $this->parseCreatedUtc($post['created_utc'] ?? null),
            'is_self' => $isSelf,
            'linked_url' => $isSelf ? null : $linkedUrl,
            'raw' => $post,
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function shouldSkip(array $post): bool
    {
        $author = $this->stringOrNull($post['author'] ?? null);
        $title = $this->stringOrNull($post['title'] ?? null) ?? '';
        $removedBy = $post['removed_by_category'] ?? null;

        if ($author === '[deleted]' || $author === '[removed]') {
            return true;
        }

        if ($title === '[deleted]' || $title === '[removed]') {
            return true;
        }

        if (is_string($removedBy) && $removedBy !== '') {
            return true;
        }

        return false;
    }

    private function parseCreatedUtc(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestamp((int) $value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
