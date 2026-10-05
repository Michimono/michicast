<?php

namespace Tests\Feature;

use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use App\Support\WebPageSourceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPageSourceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_web_page_provider_is_registered_for_forms_and_resolution(): void
    {
        $registry = $this->app->make(SourceProviderRegistry::class);

        $this->assertTrue($registry->has('web_page'));
        $this->assertInstanceOf(WebPageSourceProvider::class, $registry->get('web_page'));
        $this->assertSame('Web page (CSS selectors)', $registry->options()['web_page']);
        $this->assertTrue($registry->capabilities('web_page')->supportsConnectionTest);
    }

    public function test_a_fixture_page_with_selectors_produces_normalized_content_items(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));

        $project = Project::factory()->create();
        $items = $this->provider()->fetch($this->definition($project->id));

        $this->assertCount(3, $items);
        $this->assertFalse($items[0]->exists);
        $this->assertSame('ext:https://example.com/news/riverside-park', $items[0]->identity_key);
        $this->assertSame('City opens the riverside park', $items[0]->title);
        $this->assertSame('The riverside park opened this morning after two years of work.', $items[0]->body);
        $this->assertSame('https://example.com/news/riverside-park', $items[0]->canonical_url);
        $this->assertSame('https://example.com/news/riverside-park', $items[0]->external_id);
        $this->assertTrue(
            $items[0]->published_at?->equalTo(CarbonImmutable::parse('2026-10-01 09:30:00', 'UTC')),
        );
        $this->assertSame(['provider' => 'web_page'], $items[0]->metadata);

        $this->assertSame('https://example.com/news/library-reopens', $items[1]->canonical_url);
        $this->assertSame('Library wing reopens', $items[1]->title);
        $this->assertSame('The west wing of the city library is open again. Bookings start Monday.', $items[1]->body);
        $this->assertTrue(
            $items[1]->published_at?->equalTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC')),
        );

        $this->assertSame('https://example.com/news/tram-line', $items[2]->canonical_url);
        $this->assertTrue(
            $items[2]->published_at?->equalTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC')),
        );
        $this->assertTrue($items[2]->raw_payload['published_fallback'] ?? false);

        CarbonImmutable::setTestNow();
    }

    public function test_relative_links_resolve_to_absolute_canonical_urls(): void
    {
        Http::fake([
            'https://example.com/section/index.html' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $items = $this->provider()->fetch($this->definition(config: [
            'page_url' => 'https://example.com/section/index.html',
            'item_selector' => 'article.news-item',
            'title_selector' => 'h2.title a',
            'link_selector' => 'a.read-more',
            'body_selector' => 'p.summary',
            'published_at_selector' => 'time.published',
        ]));

        $this->assertSame('https://example.com/news/riverside-park', $items[0]->canonical_url);
        $this->assertSame('https://example.com/section/library-reopens', $items[1]->canonical_url);
        $this->assertSame('https://example.com/news/tram-line', $items[2]->canonical_url);
    }

    public function test_a_selector_matching_nothing_is_an_actionable_failure(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('empty-list.html'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame(
            'The item selector [article.news-item] matched no elements on the page. Check the selector against the page HTML.',
            $result->message,
        );

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The item selector [article.news-item] matched no elements on the page.');

        $this->provider()->fetch($this->definition());
    }

    public function test_a_repeated_fetch_persists_each_identity_only_once(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $project = Project::factory()->create();
        $definition = $this->definition($project->id);
        $provider = $this->provider();

        foreach ($provider->fetch($definition) as $item) {
            $item->persist();
        }

        foreach ($provider->fetch($definition) as $item) {
            $item->persist();
        }

        $this->assertSame(3, ContentItem::query()->where('source_id', 12)->count());
        $this->assertDatabaseHas('content_items', [
            'source_id' => 12,
            'external_id' => 'https://example.com/news/riverside-park',
            'title' => 'City opens the riverside park',
        ]);
    }

    public function test_script_and_markup_content_is_stripped_from_extracted_values(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $items = $this->provider()->fetch($this->definition());

        $this->assertStringNotContainsString('alert', (string) $items[0]->body);
        $this->assertStringNotContainsString('<script', (string) $items[0]->body);
        $this->assertStringNotContainsString('<b>', (string) $items[1]->body);
        $this->assertStringNotContainsString('color:red', (string) $items[1]->body);
        $this->assertSame('The west wing of the city library is open again. Bookings start Monday.', $items[1]->body);
    }

    public function test_connection_testing_reports_the_match_count(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertSame('Reached the page and matched 3 items.', $result->message);
    }

    public function test_an_unreachable_page_is_a_structured_connection_failure(): void
    {
        Http::fake([
            'https://example.com/news*' => function () {
                throw new ConnectionException('Could not resolve host');
            },
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The page could not be reached.', $result->message);
    }

    public function test_a_non_200_response_is_a_structured_failure(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response('Nope', 503),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The page returned HTTP 503.', $result->message);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The page returned HTTP 503.');

        $this->provider()->fetch($this->definition());
    }

    public function test_fetch_sends_an_identifying_user_agent(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $this->provider()->fetch($this->definition());

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'Michicast/1.0 (+https://github.com/Michimono/michicast)');
        });
    }

    public function test_the_item_limit_keeps_only_the_first_matched_items(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response($this->fixture('sample.html'), 200),
        ]);

        $items = $this->provider()->fetch($this->definition(config: [
            'page_url' => 'https://example.com/news/',
            'item_selector' => 'article.news-item',
            'title_selector' => 'h2.title a',
            'link_selector' => 'a.read-more',
            'body_selector' => 'p.summary',
            'published_at_selector' => 'time.published',
            'item_limit' => 1,
        ]));

        $this->assertCount(1, $items);
        $this->assertSame('City opens the riverside park', $items[0]->title);
    }

    public function test_an_oversized_page_is_a_structured_failure(): void
    {
        Http::fake([
            'https://example.com/news*' => Http::response(str_repeat('a', 2_097_153), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The page is larger than the 2097152 byte limit.', $result->message);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function definition(int $projectId = 1, int $sourceId = 12, array $config = []): SourceDefinition
    {
        return new SourceDefinition(
            'web_page',
            $config === [] ? [
                'page_url' => 'https://example.com/news/',
                'item_selector' => 'article.news-item',
                'title_selector' => 'h2.title a',
                'link_selector' => 'a.read-more',
                'body_selector' => 'p.summary',
                'published_at_selector' => 'time.published',
            ] : $config,
            $projectId,
            $sourceId,
        );
    }

    private function provider(): WebPageSourceProvider
    {
        return $this->app->make(WebPageSourceProvider::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/webpages/'.$name));
    }
}
