<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Format-agnostic reader for posts embedded in x.com HTML.
 *
 * X ships the logged-out timeline as JSON, but the wrapper keeps changing
 * (JS object literals, escaped JSON strings inside stream scripts, plain
 * application/json script tags). Instead of matching one serialization, this
 * decodes every JSON blob it can find and walks it for post-shaped objects:
 * an id, a text and a timestamp. Key order, escaping and nesting don't matter.
 */
class TwitterEmbeddedJsonParser
{
    private const MAX_BLOBS = 64;

    private const MAX_DEPTH = 80;

    /**
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     users: list<array{screen_name: string, name: string|null, post_count: int|null}>,
     *     blob_count: int
     * }
     */
    public function parse(string $html): array
    {
        $entries = [];
        $users = [];
        $blobs = $this->jsonBlobs($html);

        foreach ($blobs as $blob) {
            $this->walk($blob, $entries, $users, 0);
        }

        return [
            'entries' => $this->sortNewestFirst(array_values($entries)),
            'users' => array_values($users),
            'blob_count' => count($blobs),
        ];
    }

    /**
     * Every JSON document embedded in the page: escaped JSON string literals
     * ("{\"data\":…}") and application/json script tags.
     *
     * @return list<array<mixed>>
     */
    private function jsonBlobs(string $html): array
    {
        $blobs = [];

        if (preg_match_all('/<script[^>]*type="application\/(?:ld\+)?json"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $decoded = json_decode(trim($raw), true);

                if (is_array($decoded)) {
                    $blobs[] = $decoded;
                }
            }
        }

        $offset = 0;
        $length = strlen($html);

        while (count($blobs) < self::MAX_BLOBS && ($start = strpos($html, '"{\\"', $offset)) !== false) {
            $position = $start + 1;

            while ($position < $length) {
                $position += strcspn($html, '"\\', $position);

                if ($position >= $length) {
                    break;
                }

                if ($html[$position] === '\\') {
                    $position += 2;

                    continue;
                }

                break;
            }

            if ($position >= $length) {
                break;
            }

            $literal = substr($html, $start + 1, $position - $start - 1);
            $offset = $position + 1;

            $decoded = $this->decodeStringLiteral($literal);

            if ($decoded !== null) {
                $blobs[] = $decoded;
            }
        }

        return $blobs;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeStringLiteral(string $literal): ?array
    {
        // JS-only escapes that JSON does not accept.
        $literal = preg_replace_callback(
            '/(?<!\\\\)((?:\\\\\\\\)*)\\\\x([0-9A-Fa-f]{2})/',
            static fn (array $m): string => $m[1].'\\u00'.$m[2],
            $literal,
        ) ?? $literal;
        $literal = str_replace("\\'", "'", $literal);

        $json = json_decode('"'.$literal.'"');

        if (! is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true, 512);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     * @param  array<string, array{screen_name: string, name: string|null, post_count: int|null}>  $users
     */
    private function walk(mixed $node, array &$entries, array &$users, int $depth): void
    {
        if (! is_array($node) || $depth > self::MAX_DEPTH) {
            return;
        }

        $post = $this->postFromNode($node);

        if ($post !== null) {
            $entries['id:'.$post['external_id']] ??= $post;

            // Do not descend: quoted / retweeted originals are part of this post.
            return;
        }

        $user = $this->userFromNode($node);

        if ($user !== null) {
            $key = strtolower($user['screen_name']);
            $existing = $users[$key] ?? null;

            if ($existing === null || ($existing['post_count'] === null && $user['post_count'] !== null)) {
                $users[$key] = $user;
            }
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walk($child, $entries, $users, $depth + 1);
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null
     */
    private function postFromNode(array $node): ?array
    {
        $id = $this->digits($node['rest_id'] ?? null) ?? $this->digits($node['id_str'] ?? null);

        if ($id === null) {
            return null;
        }

        $text = $this->firstString($node, [
            'note_tweet.note_tweet_results.result.text',
            'details.full_text',
            'legacy.full_text',
            'full_text',
            'text',
        ]);

        $createdMs = $this->firstInt($node, ['details.created_at_ms', 'legacy.created_at_ms', 'created_at_ms']);
        $createdRaw = $this->firstString($node, ['legacy.created_at', 'created_at']);

        if ($text === null || ($createdMs === null && $createdRaw === null)) {
            return null;
        }

        $publishedAt = null;

        if ($createdMs !== null) {
            $publishedAt = CarbonImmutable::createFromTimestampMs($createdMs)->utc();
        } elseif ($createdRaw !== null) {
            try {
                $publishedAt = CarbonImmutable::parse($createdRaw)->utc();
            } catch (\Throwable) {
                $publishedAt = null;
            }
        }

        $screenName = $this->firstString($node, [
            'core.user_results.result.core.screen_name',
            'core.user_results.result.legacy.screen_name',
            'user.screen_name',
        ]);
        $displayName = $this->firstString($node, [
            'core.user_results.result.core.name',
            'core.user_results.result.legacy.name',
            'user.name',
        ]);

        $isRetweet = str_starts_with($text, 'RT @')
            || data_get($node, 'legacy.retweeted_status_result') !== null
            || isset($node['retweeted_status_result'])
            || isset($node['retweeted_status']);

        return [
            'external_id' => $id,
            'canonical_url' => $screenName !== null
                ? 'https://x.com/'.$screenName.'/status/'.$id
                : 'https://x.com/i/status/'.$id,
            'title' => $this->titleFromBody($text),
            'body' => $text,
            'author' => $displayName ?? ($screenName !== null ? '@'.$screenName : null),
            'published_at' => $publishedAt,
            'media' => $this->media($node),
            'is_retweet' => $isRetweet,
            'raw' => [
                'source' => 'x.com-embedded-json',
                'id_str' => $id,
                'full_text' => $text,
                'created_at_ms' => $createdMs,
                'screen_name' => $screenName,
            ],
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @return array{screen_name: string, name: string|null, post_count: int|null}|null
     */
    private function userFromNode(array $node): ?array
    {
        $screenName = $this->firstString($node, ['core.screen_name', 'legacy.screen_name', 'screen_name']);

        if ($screenName === null || ! preg_match('/^[A-Za-z0-9_]{1,15}$/', $screenName)) {
            return null;
        }

        $postCount = $this->firstInt($node, ['tweet_counts.tweets', 'legacy.statuses_count', 'statuses_count']);

        if ($postCount === null && ! isset($node['rest_id']) && ! isset($node['id_str'])) {
            return null;
        }

        return [
            'screen_name' => $screenName,
            'name' => $this->firstString($node, ['core.name', 'legacy.name', 'name']),
            'post_count' => $postCount,
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @return list<array{type: string|null, url: string, width: int|null, height: int|null, alt: string|null}>
     */
    private function media(array $node): array
    {
        $items = null;

        foreach (['media_entities2', 'legacy.extended_entities.media', 'extended_entities.media', 'legacy.entities.media', 'entities.media'] as $path) {
            $candidate = data_get($node, $path);

            if (is_array($candidate) && $candidate !== []) {
                $items = $candidate;

                break;
            }
        }

        if ($items === null) {
            return [];
        }

        $media = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $this->firstString($item, ['media_url_https', 'media_url']);

            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $type = $this->firstString($item, ['type']);

            $media[] = [
                'type' => match ($type) {
                    'photo' => 'image',
                    'video', 'animated_gif' => 'video',
                    default => $type,
                },
                'url' => $url,
                'width' => $this->firstInt($item, ['original_info.width', 'sizes.large.w']),
                'height' => $this->firstInt($item, ['original_info.height', 'sizes.large.h']),
                'alt' => $this->firstString($item, ['ext_alt_text', 'alt_text']),
            ];
        }

        return $media;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function sortNewestFirst(array $entries): array
    {
        $indexed = [];

        foreach ($entries as $index => $entry) {
            $indexed[] = [$index, $entry];
        }

        usort($indexed, static function (array $a, array $b): int {
            $aTime = $a[1]['published_at'] instanceof CarbonImmutable ? $a[1]['published_at']->getTimestampMs() : null;
            $bTime = $b[1]['published_at'] instanceof CarbonImmutable ? $b[1]['published_at']->getTimestampMs() : null;

            if ($aTime === $bTime) {
                return $a[0] <=> $b[0];
            }

            if ($aTime === null) {
                return 1;
            }

            if ($bTime === null) {
                return -1;
            }

            return $bTime <=> $aTime;
        });

        return array_map(static fn (array $pair): array => $pair[1], $indexed);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $paths
     */
    private function firstString(array $node, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($node, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $paths
     */
    private function firstInt(array $node, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($node, $path);

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function digits(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && preg_match('/^\d{1,25}$/', $value) ? $value : null;
    }

    private function titleFromBody(string $body): string
    {
        $firstLine = strtok(str_replace(["\r\n", "\r"], "\n", $body), "\n") ?: $body;
        $firstLine = trim($firstLine);

        if (mb_strlen($firstLine) <= 120) {
            return $firstLine;
        }

        return rtrim(mb_substr($firstLine, 0, 117)).'...';
    }
}
