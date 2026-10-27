<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pulls public posts out of X HTML.
 * Supports the public https://x.com/{handle} profile page (primary) and the
 * legacy syndication embed __NEXT_DATA__ payload (tests / fallback).
 */
class TwitterTimelineParser
{
    public function __construct(
        private TwitterEmbeddedJsonParser $embedded = new TwitterEmbeddedJsonParser,
    ) {}

    /**
     * Parse order, most robust first:
     *  1. legacy syndication embed (__NEXT_DATA__),
     *  2. any JSON embedded in the x.com page, walked for post-shaped objects,
     *  3. the older x.com JS-object-literal regexes.
     *
     * `posts_expected` is true when the page shows the profile has posts (a
     * post count above zero, or post text markers) but none could be read, the
     * sign that X changed its format again.
     *
     * @return array{
     *     handle: string|null,
     *     profile_found: bool,
     *     post_count: int|null,
     *     posts_expected: bool,
     *     format: string,
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
        if (str_contains($html, '__NEXT_DATA__')) {
            $embed = $this->parseSyndicationEmbed($html);

            return $embed + [
                'post_count' => null,
                'posts_expected' => false,
                'format' => 'syndication',
            ];
        }

        $embedded = $this->embedded->parse($html);
        $handle = $this->profileHandleFromHtml($html);
        $profileUser = $this->profileUser($embedded['users'], $handle);
        $postCount = $profileUser['post_count'] ?? null;
        $handle ??= $profileUser['screen_name'] ?? null;

        if ($embedded['entries'] !== []) {
            /** @var list<array{external_id: string, canonical_url: string, title: string, body: string, author: string|null, published_at: CarbonImmutable|null, media: list<array<string, mixed>>, is_retweet: bool, raw: array<string, mixed>}> $entries */
            $entries = $embedded['entries'];

            return [
                'handle' => $handle,
                'profile_found' => true,
                'post_count' => $postCount,
                'posts_expected' => false,
                'format' => 'embedded-json',
                'entries' => $entries,
            ];
        }

        try {
            $legacy = $this->parseProfilePage($html);
        } catch (SourceFetchFailedException $exception) {
            if ($profileUser === null) {
                throw $exception;
            }

            $legacy = ['handle' => $handle, 'profile_found' => true, 'entries' => []];
        }

        $postsExpected = $legacy['entries'] === [] && (
            ($postCount !== null && $postCount > 0)
            || $this->hasPostMarkers($html)
        );

        return [
            'handle' => $legacy['handle'] ?? $handle,
            'profile_found' => $legacy['profile_found'] || $profileUser !== null,
            'post_count' => $postCount,
            'posts_expected' => $postsExpected,
            'format' => 'legacy',
            'entries' => $legacy['entries'],
        ];
    }

    /**
     * @param  list<array{screen_name: string, name: string|null, post_count: int|null}>  $users
     * @return array{screen_name: string, name: string|null, post_count: int|null}|null
     */
    private function profileUser(array $users, ?string $handle): ?array
    {
        if ($handle !== null) {
            foreach ($users as $user) {
                if (strcasecmp($user['screen_name'], $handle) === 0) {
                    return $user;
                }
            }
        }

        foreach ($users as $user) {
            if ($user['post_count'] !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Post fields in any serialization (JSON, escaped JSON, JS literal).
     */
    private function hasPostMarkers(string $html): bool
    {
        return str_contains($html, 'full_text') || str_contains($html, 'tweet_results');
    }

    /**
     * @return array{
     *     handle: string|null,
     *     profile_found: bool,
     *     entries: list<array<string, mixed>>
     * }
     */
    private function parseProfilePage(string $html): array
    {
        $handle = $this->profileHandleFromHtml($html);
        $displayName = $this->profileDisplayNameFromHtml($html);
        $ids = $this->matchAll('/entry_id:"tweet-(\d+)"/', $html);
        $tweetBodies = $this->profileTweetBodies($html);

        if ($ids === [] && $tweetBodies === [] && $handle === null) {
            throw new SourceFetchFailedException('The X timeline response could not be parsed.');
        }

        $mediaById = $this->profileMediaByTweetId($html);
        $count = max(count($ids), count($tweetBodies));
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $bodyMeta = $tweetBodies[$i] ?? null;
            $body = is_array($bodyMeta) ? (string) $bodyMeta['text'] : '';
            $externalId = $ids[$i] ?? null;

            if ($externalId === null || $body === '') {
                continue;
            }

            $publishedAt = null;
            if (is_array($bodyMeta)) {
                $publishedAt = CarbonImmutable::createFromTimestampMs($bodyMeta['created_at_ms'])->utc();
            }

            $isRetweet = str_starts_with($body, 'RT @');
            $entries[] = [
                'external_id' => $externalId,
                'canonical_url' => $this->canonicalUrl(null, $handle, $externalId),
                'title' => $this->titleFromBody($body),
                'body' => $body,
                'author' => $displayName ?? ($handle !== null ? '@'.$handle : null),
                'published_at' => $publishedAt,
                'media' => $mediaById[$externalId] ?? [],
                'is_retweet' => $isRetweet,
                'raw' => [
                    'source' => 'x.com-profile',
                    'id_str' => $externalId,
                    'full_text' => $body,
                    'created_at_ms' => is_array($bodyMeta) ? $bodyMeta['created_at_ms'] : null,
                ],
            ];
        }

        $profileFound = $handle !== null
            || $entries !== []
            || str_contains($html, 'og:type" content="profile"')
            || str_contains($html, "og:type' content='profile'");

        return [
            'handle' => $handle,
            'profile_found' => $profileFound,
            'entries' => $entries,
        ];
    }

    /**
     * @return list<array{text: string, created_at_ms: int}>
     */
    private function profileTweetBodies(string $html): array
    {
        if (! preg_match_all(
            '/created_at_ms:(\d+),display_text_range:\$R\[\d+\]=\[[^\]]*\],full_text:"((?:\\\\.|[^"\\\\])*)"/',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        $bodies = [];

        foreach ($matches as $match) {
            $text = $this->decodeJsString($match[2]);

            if ($text === '') {
                continue;
            }

            $bodies[] = [
                'created_at_ms' => (int) $match[1],
                'text' => $text,
            ];
        }

        return $bodies;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function profileMediaByTweetId(string $html): array
    {
        $mediaById = [];

        if (preg_match_all(
            '/expanded_url:"https:\/\/(?:x|twitter)\.com\/[^"\/]+\/status\/(\d+)\/(?:photo|video)\/\d+".{0,400}?media_url_https:"([^"]+)".{0,200}?type:"(photo|video|animated_gif)"/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $mediaById[$match[1]][] = $this->normalizeMediaItem($match[3], $match[2]);
            }
        }

        if (preg_match_all(
            '/expanded_url:"https:\/\/(?:x|twitter)\.com\/[^"\/]+\/status\/(\d+)\/(?:photo|video)\/\d+".{0,500}?type:"(photo|video|animated_gif)".{0,200}?media_url_https:"([^"]+)"/s',
            $html,
            $altMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($altMatches as $match) {
                $mediaById[$match[1]][] = $this->normalizeMediaItem($match[2], $match[3]);
            }
        }

        return $this->uniqueMedia($mediaById);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $mediaById
     * @return array<string, list<array<string, mixed>>>
     */
    private function uniqueMedia(array $mediaById): array
    {
        foreach ($mediaById as $id => $items) {
            $seen = [];
            $unique = [];

            foreach ($items as $item) {
                $key = ($item['url'] ?? '').'|'.($item['type'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $unique[] = $item;
            }

            $mediaById[$id] = $unique;
        }

        return $mediaById;
    }

    /**
     * @return array{type: string|null, url: string|null, width: null, height: null, alt: null}
     */
    private function normalizeMediaItem(string $type, string $url): array
    {
        $normalizedType = match ($type) {
            'photo' => 'image',
            'video', 'animated_gif' => 'video',
            default => $type,
        };

        return [
            'type' => $normalizedType,
            'url' => $url,
            'width' => null,
            'height' => null,
            'alt' => null,
        ];
    }

    private function profileHandleFromHtml(string $html): ?string
    {
        if (preg_match('/screen_name:"([A-Za-z0-9_]{1,15})"/', $html, $match)) {
            return $match[1];
        }

        if (preg_match('/og:url" content="https:\/\/(?:x|twitter)\.com\/([A-Za-z0-9_]{1,15})"/', $html, $match)) {
            return $match[1];
        }

        if (preg_match('/<title>[^<]*\(@([A-Za-z0-9_]{1,15})\)[^<]*<\/title>/', $html, $match)) {
            return $match[1];
        }

        return null;
    }

    private function profileDisplayNameFromHtml(string $html): ?string
    {
        if (preg_match('/name:"((?:\\\\.|[^"\\\\])*)",screen_name:"[A-Za-z0-9_]{1,15}"/', $html, $match)) {
            return $this->decodeJsString($match[1]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function matchAll(string $pattern, string $html): array
    {
        if (! preg_match_all($pattern, $html, $matches)) {
            return [];
        }

        return array_values(array_filter($matches[1], static fn (string $v): bool => $v !== ''));
    }

    private function decodeJsString(string $value): string
    {
        $value = preg_replace_callback(
            '/\\\\x([0-9A-Fa-f]{2})/',
            static fn (array $m): string => chr(hexdec($m[1])),
            $value,
        ) ?? $value;

        $decoded = json_decode('"'.$value.'"');

        if (is_string($decoded)) {
            return $decoded;
        }

        return stripcslashes($value);
    }

    /**
     * @return array{
     *     handle: string|null,
     *     profile_found: bool,
     *     entries: list<array<string, mixed>>
     * }
     */
    private function parseSyndicationEmbed(string $html): array
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
