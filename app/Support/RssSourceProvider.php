<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads an RSS or Atom feed over HTTP and turns its entries into content items.
 */
class RssSourceProvider implements SourceProvider
{
    private const TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    private const DEFAULT_ITEM_LIMIT = 25;

    private const MAX_ITEM_LIMIT = 100;

    private const USER_AGENT = 'Michicast/1.0 (+https://github.com/Michimono/michicast)';

    public function __construct(private RssFeedParser $parser = new RssFeedParser) {}

    public function type(): string
    {
        return 'rss';
    }

    public function label(): string
    {
        return 'RSS / Atom feed';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'feed_url' => ['required', 'url', 'max:2048'],
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
                name: 'feed_url',
                label: 'Feed URL',
                type: ConfigurationFieldType::Url,
                hint: 'The RSS or Atom feed this source reads.',
            ),
            new ConfigurationField(
                name: 'item_limit',
                label: 'Item limit',
                type: ConfigurationFieldType::Number,
                hint: 'How many of the newest entries to keep, up to '.self::MAX_ITEM_LIMIT.'. Defaults to '.self::DEFAULT_ITEM_LIMIT.'.',
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

        return ConnectionTestResult::success(
            $count === 1
                ? 'Reached the feed and found 1 entry.'
                : "Reached the feed and found {$count} entries.",
        );
    }

    /**
     * @return list<ContentItem>
     */
    public function fetch(SourceDefinition $source): array
    {
        $items = [];

        foreach ($this->downloadEntries($source) as $entry) {
            if ($entry['external_id'] === null && $entry['canonical_url'] === null) {
                continue;
            }

            $items[] = ContentItem::normalize([
                'project_id' => $source->projectId,
                'source_id' => $source->sourceId,
                'external_id' => $entry['external_id'],
                'canonical_url' => $entry['canonical_url'],
                'title' => $entry['title'],
                'body' => $entry['body'],
                'author' => $entry['author'],
                'published_at' => $entry['published_at'],
                'metadata' => ['provider' => $this->type()],
                'raw_payload' => $entry['raw'],
            ]);
        }

        return $items;
    }

    /**
     * @return list<array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }>
     */
    private function downloadEntries(SourceDefinition $source): array
    {
        $feedUrl = $source->config('feed_url');

        if (! is_string($feedUrl) || trim($feedUrl) === '') {
            throw new SourceFetchFailedException('The RSS source has no feed URL to reach.');
        }

        $feedUrl = trim($feedUrl);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
                ])
                ->get($feedUrl);
        } catch (ConnectionException $exception) {
            throw new SourceFetchFailedException(
                $this->connectionFailureMessage($exception),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new SourceFetchFailedException('The feed could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The feed could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new SourceFetchFailedException(
                $this->httpFailureMessage($response->status()),
            );
        }

        $entries = $this->parser->parse($response->body());

        return array_slice($entries, 0, $this->itemLimit($source));
    }

    private function connectionFailureMessage(ConnectionException $exception): string
    {
        $detail = strtolower($exception->getMessage());

        if (str_contains($detail, 'ssl') || str_contains($detail, 'certificate')) {
            return 'The feed could not be reached because of an SSL certificate problem on this machine.';
        }

        if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout')) {
            return 'The feed timed out before it could be reached.';
        }

        return 'The feed could not be reached.';
    }

    private function httpFailureMessage(int $status): string
    {
        return match ($status) {
            403 => 'The feed refused access (HTTP 403). Some hosts block unidentified clients; check the URL or try again later.',
            429 => 'The feed rate-limited the request (HTTP 429). Wait a moment and try again.',
            default => 'The feed returned HTTP '.$status.'.',
        };
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
}
