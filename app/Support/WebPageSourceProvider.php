<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use DOMElement;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads a static HTML page with configured CSS selectors and turns matching
 * article blocks into content items.
 *
 * Published date fallback: when the published-date selector is empty, matches
 * nothing, or yields an unparseable value, the item uses the fetch timestamp
 * in UTC. Pages rarely expose reliable per-item dates, so the fetch time keeps
 * ordering stable without inventing a distant past.
 */
class WebPageSourceProvider implements SourceProvider
{
    private const TIMEOUT_SECONDS = 15;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    private const DEFAULT_ITEM_LIMIT = 25;

    private const MAX_ITEM_LIMIT = 100;

    private const MAX_RESPONSE_BYTES = 2_097_152;

    private const USER_AGENT = 'Michicast/1.0 (+https://github.com/Michimono/michicast)';

    public function __construct(private HtmlSelectorParser $parser = new DomCssHtmlSelectorParser) {}

    public function type(): string
    {
        return 'web_page';
    }

    public function label(): string
    {
        return 'Web page (CSS selectors)';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'page_url' => ['required', 'url', 'max:2048'],
            'item_selector' => ['required', 'string', 'max:512'],
            'title_selector' => ['required', 'string', 'max:512'],
            'link_selector' => ['required', 'string', 'max:512'],
            'body_selector' => ['nullable', 'string', 'max:512'],
            'published_at_selector' => ['nullable', 'string', 'max:512'],
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
                name: 'page_url',
                label: 'Page URL',
                type: ConfigurationFieldType::Url,
                hint: 'The HTML page that lists articles. JavaScript-rendered pages are not supported.',
            ),
            new ConfigurationField(
                name: 'item_selector',
                label: 'Item selector',
                type: ConfigurationFieldType::Text,
                hint: 'CSS selector for each article block on the page, for example article.news-item.',
            ),
            new ConfigurationField(
                name: 'title_selector',
                label: 'Title selector',
                type: ConfigurationFieldType::Text,
                hint: 'CSS selector for the title inside each item, for example h2 a.',
            ),
            new ConfigurationField(
                name: 'link_selector',
                label: 'Link selector',
                type: ConfigurationFieldType::Text,
                hint: 'CSS selector for the article link inside each item. Relative hrefs are resolved against the page URL. The resolved URL is the item identity.',
            ),
            new ConfigurationField(
                name: 'body_selector',
                label: 'Body selector',
                type: ConfigurationFieldType::Text,
                hint: 'Optional CSS selector for a summary or body inside each item. Scripts and markup are stripped.',
            ),
            new ConfigurationField(
                name: 'published_at_selector',
                label: 'Published date selector',
                type: ConfigurationFieldType::Text,
                hint: 'Optional CSS selector for a date inside each item (text or datetime attribute). When missing or unparseable, the fetch time in UTC is used.',
            ),
            new ConfigurationField(
                name: 'item_limit',
                label: 'Item limit',
                type: ConfigurationFieldType::Number,
                hint: 'How many of the first matched items to keep, up to '.self::MAX_ITEM_LIMIT.'. Defaults to '.self::DEFAULT_ITEM_LIMIT.'.',
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
                ? 'Reached the page and matched 1 item.'
                : "Reached the page and matched {$count} items.",
        );
    }

    /**
     * @return list<ContentItem>
     */
    public function fetch(SourceDefinition $source): array
    {
        $items = [];
        $fetchedAt = CarbonImmutable::now('UTC');

        foreach ($this->downloadEntries($source, $fetchedAt) as $entry) {
            $items[] = ContentItem::normalize([
                'project_id' => $source->projectId,
                'source_id' => $source->sourceId,
                'external_id' => $entry['external_id'],
                'canonical_url' => $entry['canonical_url'],
                'title' => $entry['title'],
                'body' => $entry['body'],
                'author' => null,
                'published_at' => $entry['published_at'],
                'metadata' => ['provider' => $this->type()],
                'raw_payload' => $entry['raw'],
            ]);
        }

        return $items;
    }

    /**
     * @return list<array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string|null,
     *     body: string|null,
     *     published_at: CarbonImmutable,
     *     raw: array<string, mixed>
     * }>
     */
    private function downloadEntries(SourceDefinition $source, ?CarbonImmutable $fetchedAt = null): array
    {
        $pageUrl = $source->config('page_url');

        if (! is_string($pageUrl) || trim($pageUrl) === '') {
            throw new SourceFetchFailedException('The web page source has no page URL to reach.');
        }

        $pageUrl = trim($pageUrl);
        $itemSelector = $this->requiredSelector($source, 'item_selector', 'item');
        $titleSelector = $this->requiredSelector($source, 'title_selector', 'title');
        $linkSelector = $this->requiredSelector($source, 'link_selector', 'link');
        $bodySelector = $this->optionalSelector($source, 'body_selector');
        $publishedSelector = $this->optionalSelector($source, 'published_at_selector');
        $fetchedAt ??= CarbonImmutable::now('UTC');

        $html = $this->downloadHtml($pageUrl);
        $this->parser->load($html);

        $itemNodes = $this->parser->query($itemSelector);

        if ($itemNodes === []) {
            throw new SourceFetchFailedException(
                'The item selector ['.$itemSelector.'] matched no elements on the page. Check the selector against the page HTML.',
            );
        }

        $entries = [];

        foreach ($itemNodes as $itemNode) {
            $entry = $this->extractEntry(
                $itemNode,
                $pageUrl,
                $titleSelector,
                $linkSelector,
                $bodySelector,
                $publishedSelector,
                $fetchedAt,
            );

            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;

            if (count($entries) >= $this->itemLimit($source)) {
                break;
            }
        }

        if ($entries === []) {
            throw new SourceFetchFailedException(
                'The item selector matched elements, but none produced a usable link. Check the link selector.',
            );
        }

        return $entries;
    }

    /**
     * @return array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string|null,
     *     body: string|null,
     *     published_at: CarbonImmutable,
     *     raw: array<string, mixed>
     * }|null
     */
    private function extractEntry(
        DOMElement $itemNode,
        string $pageUrl,
        string $titleSelector,
        string $linkSelector,
        ?string $bodySelector,
        ?string $publishedSelector,
        CarbonImmutable $fetchedAt,
    ): ?array {
        $link = $this->extractLink($itemNode, $linkSelector, $pageUrl);

        if ($link === null) {
            return null;
        }

        $title = $this->extractFieldText($itemNode, $titleSelector);
        $body = $bodySelector === null ? null : $this->extractFieldText($itemNode, $bodySelector);
        $publishedRaw = $publishedSelector === null ? null : $this->extractDateRaw($itemNode, $publishedSelector);
        $publishedAt = $this->parsePublishedAt($publishedRaw) ?? $fetchedAt;

        return [
            'external_id' => $link,
            'canonical_url' => $link,
            'title' => $title,
            'body' => $body,
            'published_at' => $publishedAt,
            'raw' => [
                'title' => $title,
                'link' => $link,
                'body' => $body,
                'published_raw' => $publishedRaw,
                'published_fallback' => $publishedRaw === null || $this->parsePublishedAt($publishedRaw) === null,
            ],
        ];
    }

    private function extractLink(DOMElement $itemNode, string $linkSelector, string $pageUrl): ?string
    {
        $nodes = $this->parser->query($linkSelector, $itemNode);

        if ($nodes === []) {
            return null;
        }

        $node = $nodes[0];
        $href = $this->parser->attribute($node, 'href')
            ?? $this->parser->attribute($node, 'data-href')
            ?? $this->nullable($this->parser->plainText($node));

        if ($href === null) {
            return null;
        }

        return $this->resolveUrl($href, $pageUrl);
    }

    private function extractFieldText(DOMElement $itemNode, string $selector): ?string
    {
        $nodes = $this->parser->query($selector, $itemNode);

        if ($nodes === []) {
            return null;
        }

        return $this->nullable($this->parser->plainText($nodes[0]));
    }

    private function extractDateRaw(DOMElement $itemNode, string $selector): ?string
    {
        $nodes = $this->parser->query($selector, $itemNode);

        if ($nodes === []) {
            return null;
        }

        $node = $nodes[0];

        return $this->parser->attribute($node, 'datetime')
            ?? $this->parser->attribute($node, 'content')
            ?? $this->nullable($this->parser->plainText($node));
    }

    private function parsePublishedAt(?string $value): ?CarbonImmutable
    {
        $value = $this->nullable($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function downloadHtml(string $pageUrl): string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'Accept' => 'text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8',
                ])
                ->get($pageUrl);
        } catch (ConnectionException $exception) {
            throw new SourceFetchFailedException(
                $this->connectionFailureMessage($exception),
                previous: $exception,
            );
        } catch (RequestException $exception) {
            throw new SourceFetchFailedException('The page could not be reached.', previous: $exception);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The page could not be reached.', previous: $exception);
        }

        if ($response->failed()) {
            throw new SourceFetchFailedException(
                $this->httpFailureMessage($response->status()),
            );
        }

        $contentLength = $response->header('Content-Length');

        if (is_numeric($contentLength) && (int) $contentLength > self::MAX_RESPONSE_BYTES) {
            throw new SourceFetchFailedException(
                'The page is larger than the '.self::MAX_RESPONSE_BYTES.' byte limit.',
            );
        }

        $html = $response->body();

        if (strlen($html) > self::MAX_RESPONSE_BYTES) {
            throw new SourceFetchFailedException(
                'The page is larger than the '.self::MAX_RESPONSE_BYTES.' byte limit.',
            );
        }

        return $html;
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with(strtolower($href), 'javascript:') || str_starts_with(strtolower($href), 'mailto:') || $href === '#') {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($href));
        } catch (Throwable) {
            return null;
        }

        if (preg_match('#^https?://#i', $resolved) !== 1) {
            return null;
        }

        return $resolved;
    }

    private function requiredSelector(SourceDefinition $source, string $key, string $label): string
    {
        $value = $source->config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new SourceFetchFailedException("The web page source has no {$label} selector.");
        }

        return trim($value);
    }

    private function optionalSelector(SourceDefinition $source, string $key): ?string
    {
        $value = $source->config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
            return 'The page could not be reached because of an SSL certificate problem on this machine.';
        }

        if (str_contains($detail, 'timed out') || str_contains($detail, 'timeout')) {
            return 'The page timed out before it could be reached.';
        }

        return 'The page could not be reached.';
    }

    private function httpFailureMessage(int $status): string
    {
        return match ($status) {
            403 => 'The page refused access (HTTP 403). Some hosts block unidentified clients; check the URL or try again later.',
            404 => 'The page was not found (HTTP 404). Check the page URL.',
            429 => 'The page rate-limited the request (HTTP 429). Wait a moment and try again.',
            default => 'The page returned HTTP '.$status.'.',
        };
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
