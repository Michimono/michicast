<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pulls public posts out of X's unauthenticated timeline embed HTML.
 * The markup and payload shape can change without notice.
 */
class TwitterTimelineParser
{
    /**
     * @return array{
     *     handle: string|null,
     *     profile_found: bool,
     *     entries: list<array{
     *         external_id: string,
     *         canonical_url: string,
     *         title: string,
     *         body: string,
     *         author: string|null,
     *         published_at: CarbonImmutable|null,
     *         media: list<array<string, mixed>>,
     *         is_retweet: bool,
     *         raw: array<string, mixed>
     *     }>
     * }
     */
    public function parse(string $html): array
    {
        $payload = $this->decodeNextData($html);
        $pageProps = data_get($payload, 'props.pageProps');

        if (! is_array($pageProps)) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.');
        }

        $handle = $this->stringOrNull(data_get($pageProps, 'headerProps.screenName'))
            ?? $this->stringOrNull(data_get($payload, 'query.screenName'));

        $timeline = data_get($pageProps, 'timeline');
        $rawEntries = is_array($timeline) ? ($timeline['entries'] ?? null) : null;

        if (! is_array($rawEntries)) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.');
        }

        $profileFound = array_key_exists('headerProps', $pageProps)
            || array_key_exists('latest_tweet_id', $pageProps)
            || $rawEntries !== [];

        $entries = [];

        foreach ($rawEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizeEntry($entry);

            if ($normalized !== null) {
                $entries[] = $normalized;
            }
        }

        return [
            'handle' => $handle,
            'profile_found' => $profileFound,
            'entries' => $entries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNextData(string $html): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $matches)) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.');
        }

        try {
            $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string,
     *     body: string,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     media: list<array<string, mixed>>,
     *     is_retweet: bool,
     *     raw: array<string, mixed>
     * }|null
     */
    private function normalizeEntry(array $entry): ?array
    {
        $tweet = data_get($entry, 'content.tweet');

        if (! is_array($tweet)) {
            return null;
        }

        $externalId = $this->stringOrNull($tweet['id_str'] ?? null);

        if ($externalId === null) {
            return null;
        }

        $body = $this->stringOrNull($tweet['full_text'] ?? null)
            ?? $this->stringOrNull($tweet['text'] ?? null)
            ?? '';

        if ($body === '') {
            return null;
        }

        $screenName = $this->stringOrNull(data_get($tweet, 'user.screen_name'));
        $displayName = $this->stringOrNull(data_get($tweet, 'user.name'));
        $permalink = $this->stringOrNull($tweet['permalink'] ?? null);
        $isRetweet = isset($tweet['retweeted_status']) || str_starts_with($body, 'RT @');

        return [
            'external_id' => $externalId,
            'canonical_url' => $this->canonicalUrl($permalink, $screenName, $externalId),
            'title' => $this->titleFromBody($body),
            'body' => $body,
            'author' => $displayName ?? ($screenName !== null ? '@'.$screenName : null),
            'published_at' => $this->parseDate($this->stringOrNull($tweet['created_at'] ?? null)),
            'media' => $this->mediaFromTweet($tweet),
            'is_retweet' => $isRetweet,
            'raw' => $tweet,
        ];
    }

    private function canonicalUrl(?string $permalink, ?string $screenName, string $externalId): string
    {
        if (is_string($permalink) && str_starts_with($permalink, '/')) {
            return 'https://x.com'.$permalink;
        }

        if ($screenName !== null) {
            return 'https://x.com/'.$screenName.'/status/'.$externalId;
        }

        return 'https://x.com/i/status/'.$externalId;
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

    /**
     * @param  array<string, mixed>  $tweet
     * @return list<array{
     *     type: string|null,
     *     url: string|null,
     *     width: int|null,
     *     height: int|null,
     *     alt: string|null
     * }>
     */
    private function mediaFromTweet(array $tweet): array
    {
        $items = data_get($tweet, 'extended_entities.media');

        if (! is_array($items) || $items === []) {
            $items = data_get($tweet, 'entities.media');
        }

        if (! is_array($items)) {
            return [];
        }

        $media = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $this->stringOrNull($item['media_url_https'] ?? null)
                ?? $this->stringOrNull($item['media_url'] ?? null);

            if ($url === null) {
                continue;
            }

            $type = $this->stringOrNull($item['type'] ?? null);
            $normalizedType = match ($type) {
                'photo' => 'image',
                'video', 'animated_gif' => 'video',
                default => $type,
            };

            $media[] = [
                'type' => $normalizedType,
                'url' => $url,
                'width' => isset($item['sizes']['large']['w']) ? (int) $item['sizes']['large']['w'] : null,
                'height' => isset($item['sizes']['large']['h']) ? (int) $item['sizes']['large']['h'] : null,
                'alt' => $this->stringOrNull($item['ext_alt_text'] ?? null)
                    ?? $this->stringOrNull($item['alt_text'] ?? null),
            ];
        }

        return $media;
    }

    public function parseDate(?string $raw): ?CarbonImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
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
