<?php

namespace Tests\Feature;

use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\RssFeedParser;
use App\Support\RssSourceProvider;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RssSourceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_rss_provider_is_registered_for_forms_and_resolution(): void
    {
        $registry = $this->app->make(SourceProviderRegistry::class);

        $this->assertTrue($registry->has('rss'));
        $this->assertInstanceOf(RssSourceProvider::class, $registry->get('rss'));
        $this->assertSame('RSS / Atom feed', $registry->options()['rss']);
        $this->assertTrue($registry->capabilities('rss')->supportsConnectionTest);
    }

    public function test_a_valid_rss_feed_produces_normalized_content_items(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        $project = Project::factory()->create();
        $items = $this->provider()->fetch($this->definition($project->id));

        $this->assertCount(2, $items);
        $this->assertFalse($items[0]->exists);
        $this->assertSame('ext:post-1', $items[0]->identity_key);
        $this->assertSame('First post', $items[0]->title);
        $this->assertSame('Hello from RSS.', $items[0]->body);
        $this->assertSame('Ada Lovelace', $items[0]->author);
        $this->assertSame('https://example.com/posts/1', $items[0]->canonical_url);
        $this->assertTrue(
            $items[0]->published_at?->equalTo(CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC')),
        );
        $this->assertSame(['provider' => 'rss'], $items[0]->metadata);

        $this->assertTrue(
            $items[1]->published_at?->equalTo(CarbonImmutable::parse('2026-03-08 13:30:00', 'UTC')),
        );
    }

    public function test_a_valid_atom_feed_produces_normalized_content_items(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.atom.xml'), 200, [
                'Content-Type' => 'application/atom+xml',
            ]),
        ]);

        $project = Project::factory()->create();
        $items = $this->provider()->fetch($this->definition($project->id));

        $this->assertCount(2, $items);
        $this->assertSame('ext:urn:uuid:entry-1', $items[0]->identity_key);
        $this->assertSame('Atom first', $items[0]->title);
        $this->assertSame('Hello from Atom.', $items[0]->body);
        $this->assertSame('Grace Hopper', $items[0]->author);
        $this->assertSame('https://example.com/atoms/1', $items[0]->canonical_url);
        $this->assertTrue(
            $items[0]->published_at?->equalTo(CarbonImmutable::parse('2026-03-07 12:00:00', 'UTC')),
        );
        $this->assertTrue(
            $items[1]->published_at?->equalTo(CarbonImmutable::parse('2026-03-08 16:00:00', 'UTC')),
        );
    }

    public function test_a_repeated_fetch_persists_each_identity_only_once(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200),
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

        $this->assertSame(2, ContentItem::query()->where('source_id', 12)->count());
        $this->assertDatabaseHas('content_items', [
            'source_id' => 12,
            'external_id' => 'post-1',
            'title' => 'First post',
        ]);
    }

    public function test_connection_testing_reports_the_entry_count(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertSame('Reached the feed and found 2 entries.', $result->message);
    }

    public function test_an_unreachable_feed_is_a_structured_connection_failure(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The feed could not be reached.', $result->message);
    }

    public function test_a_non_200_response_is_a_structured_failure(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response('Nope', 503),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The feed returned HTTP 503.', $result->message);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The feed returned HTTP 503.');

        $this->provider()->fetch($this->definition());
    }

    public function test_malformed_xml_is_a_structured_failure(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('broken.xml'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The feed could not be parsed.', $result->message);
    }

    public function test_an_unrecognised_document_is_a_structured_failure(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('malformed.xml'), 200),
        ]);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The feed is not a recognised RSS or Atom document.');

        $this->provider()->fetch($this->definition());
    }

    public function test_the_item_limit_keeps_only_the_newest_entries(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200),
        ]);

        $items = $this->provider()->fetch($this->definition(config: [
            'feed_url' => 'https://example.com/feed.xml',
            'item_limit' => 1,
        ]));

        $this->assertCount(1, $items);
        $this->assertSame('First post', $items[0]->title);
    }

    #[DataProvider('feedDateFormats')]
    public function test_published_dates_are_stored_in_utc(string $raw, string $expectedUtc): void
    {
        $parsed = (new RssFeedParser)->parseDate($raw);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->equalTo(CarbonImmutable::parse($expectedUtc, 'UTC')));
        $this->assertSame('UTC', $parsed->timezoneName);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function feedDateFormats(): array
    {
        return [
            'rfc822' => ['Sat, 07 Mar 2026 12:00:00 +0000', '2026-03-07 12:00:00'],
            'rfc822 with offset' => ['Sun, 08 Mar 2026 15:30:00 +0200', '2026-03-08 13:30:00'],
            'atom utc' => ['2026-03-07T12:00:00Z', '2026-03-07 12:00:00'],
            'atom offset' => ['2026-03-08T18:00:00+02:00', '2026-03-08 16:00:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function definition(int $projectId = 1, int $sourceId = 12, array $config = []): SourceDefinition
    {
        return new SourceDefinition(
            'rss',
            $config === [] ? ['feed_url' => 'https://example.com/feed.xml'] : $config,
            $projectId,
            $sourceId,
        );
    }

    private function provider(): RssSourceProvider
    {
        return $this->app->make(RssSourceProvider::class);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/feeds/'.$name));
    }
}
