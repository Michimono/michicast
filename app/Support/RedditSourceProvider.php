<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads posts from a subreddit through Reddit's free public Atom feed
 * (https://www.reddit.com/r/{sub}/{sort}/.rss). No OAuth, no paid API, and no
 * credentials — best-effort anonymous fetch that returns a list of posts.
 *
 * Reddit blocks anonymous requests to the listing JSON (HTTP 403, "blocked by
 * network security"), while the feed for the same listing stays public. The
 * feed has no score, comment count or flair.
 *
 * Removed or deleted posts are skipped (see RedditListingParser). Rate limits
 * are treated as transient: the request is not immediately retried, Retry-After
 * / X-Ratelimit headers are honoured in the failure message, and callers see a
 * clear actionable error.
 */
class RedditSourceProvider implements SourceProvider
{
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 500;

    private const DEFAULT_ITEM_LIMIT = 25;

    private const MAX_ITEM_LIMIT = 100;

    private const USER_AGENT = 'Michicast/1.0 (+https://github.com/Michimono/michicast; reddit-source)';

    private const SORTS = ['hot', 'new', 'top'];

    private const PERIODS = ['hour', 'day', 'week', 'month', 'year', 'all'];

    private const DEFAULT_SORT = 'hot';

    private const DEFAULT_PERIOD = 'day';

    public function __construct(private RedditListingParser $parser = new RedditListingParser) {}

    public function type(): string
    {
        return 'reddit';
    }

    public function label(): string
    {
        return 'Reddit (public feed)';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'subreddit' => ['required', 'string', 'max:52', 'regex:/^(?:r\/)?[A-Za-z0-9][A-Za-z0-9_]{1,49}$/i'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::SORTS)],
            'time_period' => ['nullable', 'string', 'in:'.implode(',', self::PERIODS)],
            'item_limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ITEM_LIMIT],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'subreddit',
                placeholder: 'pokemon',
                required: true,
                label: 'Subreddit',
                type: ConfigurationFieldType::Text,
                hint: 'Name without r/ (for example pokemon). Free public feed — no OAuth, API keys, or paid Reddit API. Each fetch returns a list of posts (one item per post), not a single post. Reddit rate-limits anonymous clients.',
            ),
            new ConfigurationField(
                name: 'sort',
                label: 'Sort',
                type: ConfigurationFieldType::Select,
                hint: 'Which public listing to read: hot, new, or top. Results are always a list of posts up to the item limit.',
                options: [
                    'hot' => 'Hot',
                    'new' => 'New',
                    'top' => 'Top',
                ],
            ),
            new ConfigurationField(
                name: 'time_period',
                label: 'Time period',
                type: ConfigurationFieldType::Select,
                hint: 'Window used when sort is top (hour, day, week, month, year, or all). Ignored for hot and new.',
                options: [
                    'hour' => 'Past hour',
                    'day' => 'Past day',
                    'week' => 'Past week',
                    'month' => 'Past month',
                    'year' => 'Past year',
                    'all' => 'All time',
                ],
            ),
            new ConfigurationField(
                name: 'item_limit',
                placeholder: (string) self::DEFAULT_ITEM_LIMIT,
                optional: true,
                label: 'Item limit',
                type: ConfigurationFieldType::Number,
                hint: 'How many posts to keep from the listing as a list/array of items, up to '.self::MAX_ITEM_LIMIT.'. Defaults to '.self::DEFAULT_ITEM_LIMIT.'.',
            ),
        ];
    }

    public function capabilities(): SourceProviderCapabilities
    {
        return new SourceProviderCapabilities(supportsConnectionTest: true);
    }

    public function testConnection(SourceDefinition $source): ConnectionTestResult
    {
        try {
            $entries = $this->downloadEntries($source);
        } catch (SourceFetchFailedException $exception) {
            return ConnectionTestResult::failure($exception->getMessage());
        }

        $count = count($entries);
        $subreddit = $this->normalizedSubreddit($source) ?? 'the subreddit';

        return ConnectionTestResult::success(
            $count === 1
                ? "Reached r/{$subreddit} and found 1 public post."
                : "Reached r/{$subreddit} and found {$count} public posts.",
        );
    }

    /**
     * @return list<ContentItem>
     */
    public function fetch(SourceDefinition $source): array
    {
        $items = [];

        foreach ($this->downloadEntries($source) as $entry) {
            $items[] = ContentItem::normalize([
                'project_id' => $source->projectId,
                'source_id' => $source->sourceId,
                'external_id' => $entry['external_id'],
                'canonical_url' => $entry['canonical_url'],
                'title' => $entry['title'],
                'body' => $entry['body'],
                'author' => $entry['author'],
                'published_at' => $entry['published_at'],
                'metadata' => [
                    'provider' => $this->type(),
                    'post_kind' => $entry['is_self'] ? 'self' : 'link',
                    'linked_url' => $entry['linked_url'],
                    // Extra origin fields the mapping editor exposes for Reddit sources.
                    'source_fields' => [
                        'text' => $entry['text'],
                        'linked_url' => $entry['linked_url'],
                        'preview_image' => $entry['preview_image'] === null ? [] : [
                            ['type' => 'image', 'url' => $entry['preview_image']],
                        ],
                    ],
                ],
                'raw_payload' => $entry['raw'],
            ]);
        }

        return $items;
    }

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
     *     text: string|null,
     *     preview_image: string|null,
     *     raw: array<string, mixed>
     * }>
     */
    private function downloadEntries(SourceDefinition $source): array
    {
        $subreddit = $this->normalizedSubreddit($source);

        if ($subreddit === null) {
            throw new SourceFetchFailedException('The Reddit source has no subreddit to reach.');
        }

        $sort = $this->sort($source);
        $limit = $this->itemLimit($source);
        $url = $this->listingUrl($subreddit, $sort);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    fn (int $attempt, ?Throwable $exception): int => $this->retryDelayMilliseconds($attempt, $exception),
                    // Redirects reach this callback without an exception; they are never retried.
                    fn (?Throwable $exception): bool => $exception !== null && $this->shouldRetry($exception),
                    throw: false,
                )
                ->withoutRedirecting()
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'Accept' => 'application/atom+xml, application/xml;q=0.9, text/xml;q=0.8',
                ])
                ->get($url, $this->queryParameters($sort, $limit, $source));
        } catch (ConnectionException $exception) {
            throw new SourceFetchFailedException(
                $this->connectionFailureMessage($exception),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new SourceFetchFailedException('The Reddit feed could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The Reddit feed could not be reached.', previous: $exception);
        }

        if ($response->status() === 404 || $this->redirectsToSubredditSearch($response)) {
            throw new SourceFetchFailedException(
                "Subreddit r/{$subreddit} was not found. Check the name.",
            );
        }

        if ($response->status() === 403) {
            throw new SourceFetchFailedException($this->forbiddenMessage($subreddit, $response));
        }

        if ($response->redirect()) {
            throw new SourceFetchFailedException(
                "Reddit redirected the feed for r/{$subreddit} instead of returning it (HTTP {$response->status()}). The community may require signing in.",
            );
        }

        if ($response->failed()) {
            throw new SourceFetchFailedException(
                $this->httpFailureMessage($response),
            );
        }

        return array_slice($this->parser->parse($response->body()), 0, $limit);
    }

    private function listingUrl(string $subreddit, string $sort): string
    {
        return sprintf(
            'https://www.reddit.com/r/%s/%s/.rss',
            rawurlencode($subreddit),
            rawurlencode($sort),
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function queryParameters(string $sort, int $limit, SourceDefinition $source): array
    {
        $query = ['limit' => $limit, 'raw_json' => 1];

        if ($sort === 'top') {
            $query['t'] = $this->timePeriod($source);
        }

        return $query;
    }

    private function normalizedSubreddit(SourceDefinition $source): ?string
    {
        $value = $source->config('subreddit');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('#^r/#i', '', $value) ?? $value;
        $value = ltrim($value, '/');

        if ($value === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_]{1,49}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function sort(SourceDefinition $source): string
    {
        $value = $source->config('sort');

        if (is_string($value) && in_array($value, self::SORTS, true)) {
            return $value;
        }

        return self::DEFAULT_SORT;
    }

    private function timePeriod(SourceDefinition $source): string
    {
        $value = $source->config('time_period');

        if (is_string($value) && in_array($value, self::PERIODS, true)) {
            return $value;
        }

        return self::DEFAULT_PERIOD;
    }

    private function itemLimit(SourceDefinition $source): int
    {
        $configured = $source->config('item_limit');

        if (is_numeric($configured)) {
            $limit = (int) $configured;

            if ($limit >= 1) {
                return min($limit, self::MAX_ITEM_LIMIT);
            }
        }

        return self::DEFAULT_ITEM_LIMIT;
    }

    /**
     * Reddit answers a feed for a subreddit that does not exist with a redirect
     * to its subreddit search.
     */
    private function redirectsToSubredditSearch(Response $response): bool
    {
        if (! $response->redirect()) {
            return false;
        }

        return str_contains(strtolower((string) $response->header('Location')), '/subreddits/search');
    }

    private function forbiddenMessage(string $subreddit, Response $response): string
    {
        if (str_contains(strtolower($response->body()), 'blocked by network security')) {
            return 'Reddit blocked the request from this server (HTTP 403). This is on Reddit\'s side, not the subreddit name; try again later.';
        }

        return "Reddit refused access to r/{$subreddit} (HTTP 403). The community may be private or quarantined.";
    }

    private function connectionFailureMessage(ConnectionException $exception): string
    {
        $detail = strtolower($exception->getMessage());

        if (str_contains($detail, 'ssl') || str_contains($detail, 'certificate')) {
            return 'The Reddit feed could not be reached because of an SSL certificate problem on this machine.';
        }

        if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout')) {
            return 'The Reddit feed timed out before it could be reached.';
        }

        return 'The Reddit feed could not be reached.';
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        // Do not immediately repeat a rate-limited request — treat 429 as transient.
        return $status === 408 || $status === 425 || $status >= 500;
    }

    private function retryDelayMilliseconds(int $attempt, ?Throwable $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = $this->retryAfterMilliseconds($exception->response);

            if ($retryAfter !== null) {
                return $retryAfter;
            }
        }

        return self::RETRY_SLEEP_MS * (2 ** max(0, $attempt - 1));
    }

    private function retryAfterMilliseconds(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));

        if ($value !== '') {
            if (ctype_digit($value)) {
                return (int) $value * 1000;
            }

            try {
                return max(0, CarbonImmutable::parse($value)->timestamp - now()->timestamp) * 1000;
            } catch (Throwable) {
                // Fall through to X-Ratelimit / exponential delay.
            }
        }

        $reset = trim((string) $response->header('X-Ratelimit-Reset'));

        if (ctype_digit($reset)) {
            $seconds = max(0, (int) $reset - now()->timestamp);

            if ($seconds > 0) {
                return $seconds * 1000;
            }
        }

        return null;
    }

    private function httpFailureMessage(Response $response): string
    {
        $status = $response->status();

        return match ($status) {
            403 => 'Reddit refused access (HTTP 403). The subreddit may be private or quarantined.',
            404 => 'Reddit returned HTTP 404 for that subreddit. Check the name.',
            429 => $this->rateLimitFailureMessage($response),
            default => 'Reddit returned HTTP '.$status.'.',
        };
    }

    private function rateLimitFailureMessage(Response $response): string
    {
        $waitMilliseconds = $this->retryAfterMilliseconds($response);

        if ($waitMilliseconds !== null) {
            $seconds = (int) ceil($waitMilliseconds / 1000);

            return 'Reddit rate-limited the request (HTTP 429). This is transient — wait '.$this->formatWait($seconds).' and try again once.';
        }

        $remaining = trim((string) $response->header('X-Ratelimit-Remaining'));

        if ($remaining === '0' || $remaining === '0.0') {
            return 'Reddit rate-limited the request (HTTP 429). This is transient — wait a moment and try again once.';
        }

        return 'Reddit rate-limited the request (HTTP 429). This is transient — wait a moment and try again once.';
    }

    private function formatWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' seconds';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }
}
