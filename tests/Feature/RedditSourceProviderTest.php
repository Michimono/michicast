<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Mapping\ContentItemSourceData;
use App\Support\Mapping\DefaultMappings;
use App\Support\Mapping\Schemas\RedditSourceSchema;
use App\Support\RedditSourceProvider;
use App\Support\SourceDefinition;
use App\Support\SourceProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RedditSourceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_reddit_provider_is_registered_for_forms_and_resolution(): void
    {
        $registry = $this->app->make(SourceProviderRegistry::class);

        $this->assertTrue($registry->has('reddit'));
        $this->assertInstanceOf(RedditSourceProvider::class, $registry->get('reddit'));
        $this->assertSame('Reddit (public feed)', $registry->options()['reddit']);
        $this->assertTrue($registry->capabilities('reddit')->supportsConnectionTest);

        $fields = $registry->configurationFields('reddit');

        $this->assertSame(['subreddit', 'sort', 'time_period', 'item_limit'], array_map(
            static fn ($field) => $field->name,
            $fields,
        ));
        $this->assertSame(ConfigurationFieldType::Text, $fields[0]->type);
        $this->assertStringContainsString('Free public feed', (string) $fields[0]->hint);
        $this->assertStringContainsString('list of posts', (string) $fields[0]->hint);
        $this->assertStringContainsString('list/array of items', (string) $fields[3]->hint);
        $this->assertSame(ConfigurationFieldType::Select, $fields[1]->type);
        $this->assertSame(ConfigurationFieldType::Select, $fields[2]->type);
    }

    public function test_reddit_origin_schema_lists_expected_fields(): void
    {
        $schema = RedditSourceSchema::make();
        $names = array_map(static fn ($field) => $field->name, $schema->fields);

        $this->assertSame(['title', 'text', 'linked_url', 'preview_image', 'link', 'published_at', 'author'], $names);
        $this->assertSame($names, array_keys(RedditSourceSchema::sample()));
    }

    public function test_a_subreddit_listing_produces_normalized_content_items(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $project = Project::factory()->create();
        $items = $this->provider()->fetch($this->definition($project->id));

        $this->assertCount(2, $items);
        $this->assertFalse($items[0]->exists);
        $this->assertSame('ext:abc123', $items[0]->identity_key);
        $this->assertSame('Weekly game discussion thread', $items[0]->title);
        $this->assertSame('Share your decks and questions for this week.', $items[0]->body);
        $this->assertSame('mod_account', $items[0]->author);
        $this->assertSame(
            'https://www.reddit.com/r/pokemon/comments/abc123/weekly_game_discussion_thread/',
            $items[0]->canonical_url,
        );
        $this->assertTrue(
            $items[0]->published_at?->equalTo(CarbonImmutable::createFromTimestamp(1728129600)->utc()),
        );
        $this->assertSame([
            'provider' => 'reddit',
            'post_kind' => 'self',
            'linked_url' => null,
            'source_fields' => [
                'text' => 'Share your decks and questions for this week.',
                'linked_url' => null,
                'preview_image' => [],
            ],
        ], $items[0]->metadata);

        $this->assertSame('def456', $items[1]->external_id);
        $this->assertSame('Official expansion announcement', $items[1]->title);
        $this->assertSame('https://example.com/posts/expansion?a=1&b=2', $items[1]->body);
        $this->assertSame('newsbot', $items[1]->author);
        $this->assertSame('link', $items[1]->metadata['post_kind']);
        $this->assertSame('https://example.com/posts/expansion?a=1&b=2', $items[1]->metadata['linked_url']);
        $this->assertSame([
            'text' => null,
            'linked_url' => 'https://example.com/posts/expansion?a=1&b=2',
            'preview_image' => [['type' => 'image', 'url' => 'https://preview.redd.it/def456.jpeg?width=640&crop=smart']],
        ], $items[1]->metadata['source_fields']);
        $this->assertSame(
            'https://www.reddit.com/r/pokemon/comments/def456/official_expansion_announcement/',
            $items[1]->canonical_url,
        );
    }

    public function test_removed_posts_are_skipped(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $items = $this->provider()->fetch($this->definition());

        $ids = array_map(static fn (ContentItem $item) => $item->external_id, $items);

        $this->assertSame(['abc123', 'def456'], $ids);
        $this->assertNotContains('gone789', $ids);
    }

    #[DataProvider('sortOrders')]
    public function test_each_sort_order_requests_the_matching_public_feed_path(string $sort, ?string $period, string $expectedUrl): void
    {
        Http::fake([
            $expectedUrl.'*' => Http::response($this->fixture('sample-feed.xml'), 200, [
                'Content-Type' => 'application/atom+xml; charset=UTF-8',
            ]),
        ]);

        $config = [
            'subreddit' => 'pokemon',
            'sort' => $sort,
            'item_limit' => 10,
        ];

        if ($period !== null) {
            $config['time_period'] = $period;
        }

        $items = $this->provider()->fetch($this->definition(config: $config));

        $this->assertCount(2, $items);

        Http::assertSent(function (Request $request) use ($expectedUrl, $sort, $period) {
            if (! str_starts_with($request->url(), $expectedUrl)) {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['limit'] ?? 0) !== 10) {
                return false;
            }

            if ($sort === 'top') {
                return ($query['t'] ?? null) === ($period ?? 'day');
            }

            return ! array_key_exists('t', $query);
        });
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function sortOrders(): array
    {
        return [
            'hot' => ['hot', null, 'https://www.reddit.com/r/pokemon/hot/.rss'],
            'new' => ['new', null, 'https://www.reddit.com/r/pokemon/new/.rss'],
            'top' => ['top', 'week', 'https://www.reddit.com/r/pokemon/top/.rss'],
        ];
    }

    public function test_a_repeated_fetch_persists_each_identity_only_once(): void
    {
        $this->fakeListing('pokemon', 'hot');

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
            'external_id' => 'abc123',
            'title' => 'Weekly game discussion thread',
        ]);
    }

    public function test_connection_testing_reports_the_post_count(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertSame('Reached r/pokemon and found 2 public posts.', $result->message);
    }

    public function test_requests_send_an_identifying_user_agent(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $this->assertTrue($this->provider()->testConnection($this->definition())->successful);

        Http::assertSent(function (Request $request) {
            return str_starts_with($request->url(), 'https://www.reddit.com/r/pokemon/hot/.rss')
                && $request->hasHeader(
                    'User-Agent',
                    'Michicast/1.0 (+https://github.com/Michimono/michicast; reddit-source)',
                )
                && str_contains($request->header('Accept')[0] ?? '', 'application/atom+xml');
        });
    }

    public function test_a_missing_subreddit_is_reported_as_not_found(): void
    {
        Http::fake([
            'https://www.reddit.com/r/nope/hot/.rss*' => Http::response('', 302, [
                'Location' => 'https://www.reddit.com/subreddits/search.rss?q=nope',
            ]),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'nope',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame('Subreddit r/nope was not found. Check the name.', $result->message);
        $this->assertCount(0, $this->recordedUrlsMatching('subreddits/search'));
    }

    public function test_a_404_is_reported_as_not_found(): void
    {
        Http::fake([
            'https://www.reddit.com/r/nope/hot/.rss*' => Http::response('Not Found', 404),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'nope',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame('Subreddit r/nope was not found. Check the name.', $result->message);
    }

    public function test_a_forbidden_subreddit_is_reported_as_private_or_quarantined(): void
    {
        Http::fake([
            'https://www.reddit.com/r/privateclub/hot/.rss*' => Http::response('Forbidden', 403),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'privateclub',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'Reddit refused access to r/privateclub (HTTP 403). The community may be private or quarantined.',
            $result->message,
        );
    }

    public function test_a_network_security_block_does_not_blame_the_subreddit(): void
    {
        Http::fake([
            'https://www.reddit.com/r/PTCGP/hot/.rss*' => Http::response(
                '<html><body>You\'ve been blocked by network security.</body></html>',
                403,
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'PTCGP',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'Reddit blocked the request from this server (HTTP 403). This is on Reddit\'s side, not the subreddit name; try again later.',
            $result->message,
        );
    }

    public function test_image_and_gallery_posts_keep_their_outbound_link_and_self_text_is_plain(): void
    {
        $permalink = 'https://www.reddit.com/r/PTCGP/comments/1xh0138/implementing_a_story_mode/';
        $imagePermalink = 'https://www.reddit.com/r/PTCGP/comments/1xh2ogm/i_got_this_card/';
        $selfHtml = '<!-- SC_OFF --><div class="md"><p>Imagine battling gym leaders &amp; more!</p> <p>The possibilities are endless!</p> </div><!-- SC_ON --> &#32; submitted by &#32; <a href="https://www.reddit.com/user/Lassagna12"> /u/Lassagna12 </a> <br/> <span><a href="'.$permalink.'">[link]</a></span> &#32; <span><a href="'.$permalink.'">[comments]</a></span>';
        $imageHtml = '<table> <tr><td> <a href="'.$imagePermalink.'"> <img src="https://preview.redd.it/092.jpeg?width=640" /> </a> </td><td> <!-- SC_OFF --><div class="md"><p>I still do not know how to play</p> </div><!-- SC_ON --> &#32; submitted by &#32; <a href="https://www.reddit.com/user/_Phantom404_"> /u/_Phantom404_ </a> <br/> <span><a href="https://i.redd.it/092.jpeg">[link]</a></span> &#32; <span><a href="'.$imagePermalink.'">[comments]</a></span> </td></tr></table>';
        $feed = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"><title>PTCGP</title>'
            .'<entry><author><name>/u/Lassagna12</name></author><content type="html">'.htmlspecialchars($selfHtml).'</content><id>t3_1xh0138</id><link href="'.$permalink.'" /><published>2026-10-27T18:09:15+00:00</published><title>Implementing a story mode?</title></entry>'
            .'<entry><author><name>/u/_Phantom404_</name></author><content type="html">'.htmlspecialchars($imageHtml).'</content><id>t3_1xh2ogm</id><media:thumbnail url="https://preview.redd.it/092.jpeg?width=640" /><link href="'.$imagePermalink.'" /><published>2026-10-27T19:46:38+00:00</published><title>I got this card</title></entry>'
            .'</feed>';

        Http::fake([
            'https://www.reddit.com/r/PTCGP/new/.rss*' => Http::response($feed, 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $items = $this->provider()->fetch($this->definition(config: ['subreddit' => 'PTCGP', 'sort' => 'new']));

        $this->assertCount(2, $items);
        $this->assertSame('1xh0138', $items[0]->external_id);
        $this->assertSame('self', $items[0]->metadata['post_kind']);
        $this->assertSame("Imagine battling gym leaders & more!\n\nThe possibilities are endless!", $items[0]->body);
        $this->assertSame('Lassagna12', $items[0]->author);
        $this->assertSame($permalink, $items[0]->canonical_url);

        $this->assertSame('link', $items[1]->metadata['post_kind']);
        $this->assertSame("I still do not know how to play\n\nhttps://i.redd.it/092.jpeg", $items[1]->body);
        $this->assertSame('https://i.redd.it/092.jpeg', $items[1]->metadata['linked_url']);
        $this->assertSame($imagePermalink, $items[1]->canonical_url);
        $this->assertTrue($items[1]->published_at?->equalTo(CarbonImmutable::parse('2026-10-27T19:46:38+00:00')));

        $selfFields = ContentItemSourceData::from($items[0]);
        $this->assertSame("Imagine battling gym leaders & more!\n\nThe possibilities are endless!", $selfFields['text']);
        $this->assertNull($selfFields['linked_url']);
        $this->assertSame([], $selfFields['preview_image']);

        $imageFields = ContentItemSourceData::from($items[1]);
        $this->assertSame('I still do not know how to play', $imageFields['text']);
        $this->assertSame('https://i.redd.it/092.jpeg', $imageFields['linked_url']);
        $this->assertSame([['type' => 'image', 'url' => 'https://i.redd.it/092.jpeg']], $imageFields['preview_image']);
        $this->assertSame($imageFields['body'], $items[1]->body);
    }

    public function test_default_reddit_mappings_send_the_preview_image_as_media(): void
    {
        $defaults = new DefaultMappings;

        foreach (['telegram', 'recording'] as $destination) {
            $steps = $defaults->for('reddit', $destination)?->toArray()['steps'] ?? [];
            $media = collect($steps)->firstWhere('config.field', 'media');

            $this->assertSame('{{preview_image}}', $media['config']['value'] ?? null, $destination);

            $text = collect($steps)->firstWhere('config.field', 'text');
            $this->assertStringContainsString('{{text}}', $text['config']['value'] ?? '', $destination);
            $this->assertStringNotContainsString('{{body}}', $text['config']['value'] ?? '', $destination);
        }

        $rssMedia = collect($defaults->for('rss', 'telegram')?->toArray()['steps'] ?? [])->firstWhere('config.field', 'media');
        $this->assertSame('{{media}}', $rssMedia['config']['value'] ?? null);
    }

    public function test_a_rate_limited_response_is_not_retried_and_reports_transient_retry_after(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot/.rss*' => Http::response(
                'Slow down',
                429,
                ['Retry-After' => '120'],
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame(
            'Reddit rate-limited the request (HTTP 429). This is transient — wait 2 minutes and try again once.',
            $result->message,
        );
        $this->assertCount(1, $this->recordedUrlsMatching('https://www.reddit.com/r/pokemon/hot/.rss'));
    }

    public function test_an_unreachable_listing_is_a_structured_connection_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot/.rss*' => function () {
                throw new ConnectionException('Could not resolve host');
            },
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit feed could not be reached.', $result->message);
    }

    public function test_a_malformed_feed_is_a_structured_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot/.rss*' => Http::response($this->fixture('broken.xml'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit feed response could not be parsed.', $result->message);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The Reddit feed response could not be parsed.');

        $this->provider()->fetch($this->definition());
    }

    public function test_an_unexpected_payload_shape_is_a_structured_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot/.rss*' => Http::response(
                $this->fixture('unexpected-shape.xml'),
                200,
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit feed response had an unexpected shape.', $result->message);
    }

    public function test_the_item_limit_keeps_only_the_first_entries(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $items = $this->provider()->fetch($this->definition(config: [
            'subreddit' => 'pokemon',
            'item_limit' => 1,
        ]));

        $this->assertCount(1, $items);
        $this->assertSame('abc123', $items[0]->external_id);
    }

    public function test_subreddit_names_accept_an_optional_r_prefix(): void
    {
        $this->fakeListing('pokemon', 'hot');

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'r/pokemon',
        ]));

        $this->assertTrue($result->successful);
        Http::assertSent(fn (Request $request) => str_starts_with(
            $request->url(),
            'https://www.reddit.com/r/pokemon/hot/.rss',
        ));
    }

    public function test_configuration_is_validated_against_the_provider_rules(): void
    {
        $rules = $this->app->make(SourceProviderRegistry::class)->configurationRules('reddit');

        $this->assertTrue(Validator::make([
            'subreddit' => 'pokemon',
            'sort' => 'top',
            'time_period' => 'week',
            'item_limit' => 10,
        ], $rules)->passes());

        $this->assertTrue(Validator::make(['subreddit' => 'r/pokemon'], $rules)->passes());
        $this->assertTrue(Validator::make(['subreddit' => 'bad name!'], $rules)->fails());
        $this->assertTrue(Validator::make(['subreddit' => ''], $rules)->fails());
        $this->assertTrue(Validator::make([
            'subreddit' => 'pokemon',
            'sort' => 'rising',
        ], $rules)->fails());
    }

    public function test_an_empty_listing_succeeds_with_zero_posts(): void
    {
        Http::fake([
            'https://www.reddit.com/r/quietsub/hot/.rss*' => Http::response(
                $this->fixture('empty-feed.xml'),
                200,
                ['Content-Type' => 'application/atom+xml; charset=UTF-8'],
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'quietsub',
        ]));

        $this->assertTrue($result->successful);
        $this->assertSame('Reached r/quietsub and found 0 public posts.', $result->message);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function definition(int $projectId = 1, int $sourceId = 12, array $config = []): SourceDefinition
    {
        return new SourceDefinition(
            'reddit',
            $config === [] ? ['subreddit' => 'pokemon'] : $config,
            $projectId,
            $sourceId,
        );
    }

    private function provider(): RedditSourceProvider
    {
        return $this->app->make(RedditSourceProvider::class);
    }

    private function fakeListing(string $subreddit, string $sort): void
    {
        Http::fake([
            'https://www.reddit.com/r/'.$subreddit.'/'.$sort.'/.rss*' => Http::response(
                $this->fixture('sample-feed.xml'),
                200,
                ['Content-Type' => 'application/atom+xml; charset=UTF-8'],
            ),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/reddit/'.$name));
    }

    /**
     * @return list<string>
     */
    private function recordedUrlsMatching(string $needle): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_starts_with($url, $needle) || str_contains($url, $needle))
            ->values()
            ->all();
    }
}
