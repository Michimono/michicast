<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Reads public posts for an X/Twitter handle via the public profile page
 * (https://x.com/{handle}), the same first-party path that works with curl -L.
 * No API keys and no third-party bridges — best-effort only.
 */
class TwitterSourceProvider implements SourceProvider
{
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 500;

    private const DEFAULT_ITEM_LIMIT = 25;

    private const MAX_ITEM_LIMIT = 100;

    public function __construct(
        private TwitterTimelineParser $parser = new TwitterTimelineParser,
        private TwitterSyndicationClient $http = new TwitterSyndicationClient,
    ) {}

    public function type(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'Twitter / X (public profile)';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'handle' => ['required', 'string', 'max:16', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
            'item_limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ITEM_LIMIT],
            'include_retweets' => ['nullable', 'string', 'in:yes,no'],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'handle',
                label: 'Handle',
                type: ConfigurationFieldType::Text,
                hint: 'Public @username only. Best-effort scrape of the public X profile page — may break when X changes or blocks requests. Private accounts are not supported.',
            ),
            new ConfigurationField(
                name: 'item_limit',
                label: 'Item limit',
                type: ConfigurationFieldType::Number,
                hint: 'How many of the newest posts to keep, up to '.self::MAX_ITEM_LIMIT.'. Defaults to '.self::DEFAULT_ITEM_LIMIT.'.',
            ),
            new ConfigurationField(
                name: 'include_retweets',
                label: 'Include retweets',
                type: ConfigurationFieldType::Select,
                hint: 'Whether to keep posts that are retweets of other accounts.',
                options: [
                    'yes' => 'Yes',
                    'no' => 'No',
                ],
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
        $handle = $this->normalizedHandle($source) ?? 'the profile';

        return ConnectionTestResult::success(
            $count === 1
                ? "Reached @{$handle} and found 1 public post."
                : "Reached @{$handle} and found {$count} public posts.",
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
                'media' => $entry['media'] === [] ? null : $entry['media'],
                'metadata' => [
                    'provider' => $this->type(),
                    'is_retweet' => $entry['is_retweet'],
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
     *     body: string,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     media: list<array<string, mixed>>,
     *     is_retweet: bool,
     *     raw: array<string, mixed>
     * }>
     */
    private function downloadEntries(SourceDefinition $source): array
    {
        $handle = $this->normalizedHandle($source);

        if ($handle === null) {
            throw new SourceFetchFailedException('The Twitter / X source has no handle to reach.');
        }

        try {
            $response = $this->http->pendingProfileRequest()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    fn (int $attempt, Throwable $exception): int => $this->retryDelayMilliseconds($attempt, $exception),
                    fn (Throwable $exception): bool => $this->shouldRetry($exception),
                    throw: false,
                )
                ->get($this->http->profileUrl($handle));
        } catch (ConnectionException $exception) {
            throw new SourceFetchFailedException(
                $this->connectionFailureMessage($exception),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new SourceFetchFailedException('The X profile could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The X profile could not be reached.', previous: $exception);
        }

        if ($response->status() === 404) {
            throw new SourceFetchFailedException(
                "No public posts were found for @{$handle}. The account may not exist, may be protected, or X may be blocking unauthenticated reads.",
            );
        }

        if ($response->failed()) {
            throw new SourceFetchFailedException(
                $this->httpFailureMessage($response),
            );
        }

        $parsed = $this->parser->parse($response->body());

        if ($parsed['entries'] === [] && ! $parsed['profile_found']) {
            throw new SourceFetchFailedException(
                "No public posts were found for @{$handle}. The account may not exist, may be protected, or X may be blocking unauthenticated reads.",
            );
        }

        $entries = $parsed['entries'];

        if (! $this->includeRetweets($source)) {
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => ! $entry['is_retweet'],
            ));
        }

        return array_slice($entries, 0, $this->itemLimit($source));
    }

    private function normalizedHandle(SourceDefinition $source): ?string
    {
        $handle = $source->config('handle');

        if (! is_string($handle)) {
            return null;
        }

        $handle = ltrim(trim($handle), '@');

        if ($handle === '' || ! preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle)) {
            return null;
        }

        return $handle;
    }

    private function includeRetweets(SourceDefinition $source): bool
    {
        $value = $source->config('include_retweets');

        if ($value === null || $value === '') {
            return true;
        }

        return $value !== 'no' && $value !== false && $value !== 0 && $value !== '0';
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

    private function connectionFailureMessage(ConnectionException $exception): string
    {
        $detail = strtolower($exception->getMessage());

        if (str_contains($detail, 'ssl') || str_contains($detail, 'certificate')) {
            return 'The X profile could not be reached because of an SSL certificate problem on this machine.';
        }

        if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout')) {
            return 'The X profile timed out before it could be reached.';
        }

        return 'The X profile could not be reached.';
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

        // Do not immediately repeat a rate-limited request.
        return $status === 408 || $status === 425 || $status >= 500;
    }

    private function retryDelayMilliseconds(int $attempt, Throwable $exception): int
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
                // Fall through to the normal exponential delay.
            }
        }

        return null;
    }

    private function httpFailureMessage(Response $response): string
    {
        $status = $response->status();

        return match ($status) {
            403 => 'X refused access (HTTP 403). Unauthenticated profile reads are often blocked; try again later or use another source.',
            404 => 'X returned HTTP 404 for that handle. Check the username or whether the account is public.',
            429 => $this->rateLimitFailureMessage($response),
            default => 'X returned HTTP '.$status.'.',
        };
    }

    private function rateLimitFailureMessage(Response $response): string
    {
        $waitMilliseconds = $this->retryAfterMilliseconds($response);

        if ($waitMilliseconds !== null) {
            $seconds = (int) ceil($waitMilliseconds / 1000);

            return 'X rate-limited the request (HTTP 429). Wait '.$this->formatWait($seconds).' and try again once.';
        }

        $reset = trim((string) $response->header('x-rate-limit-reset'));

        if (ctype_digit($reset)) {
            $seconds = max(0, (int) $reset - now()->timestamp);

            if ($seconds > 0) {
                return 'X rate-limited the request (HTTP 429). Try again in '.$this->formatWait($seconds).'.';
            }
        }

        return 'X rate-limited the request (HTTP 429). Wait a moment and try again once.';
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
