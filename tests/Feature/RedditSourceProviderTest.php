<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
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
        $this->assertSame('Reddit (public JSON)', $registry->options()['reddit']);
        $this->assertTrue($registry->capabilities('reddit')->supportsConnectionTest);

        $fields = $registry->configurationFields('reddit');

        $this->assertSame(['subreddit', 'sort', 'time_period', 'item_limit'], array_map(
            static fn ($field) => $field->name,
            $fields,
        ));
        $this->assertSame(ConfigurationFieldType::Text, $fields[0]->type);
        $this->assertStringContainsString('Free public JSON', (string) $fields[0]->hint);
        $this->assertStringContainsString('list of posts', (string) $fields[0]->hint);
        $this->assertStringContainsString('list/array of items', (string) $fields[3]->hint);
        $this->assertSame(ConfigurationFieldType::Select, $fields[1]->type);
        $this->assertSame(ConfigurationFieldType::Select, $fields[2]->type);
    }

    public function test_reddit_origin_schema_lists_expected_fields(): void
    {
        $schema = RedditSourceSchema::make();
        $names = array_map(static fn ($field) => $field->name, $schema->fields);

        $this->assertSame(['title', 'body', 'link', 'published_at', 'author'], $names);
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
        ], $items[0]->metadata);

        $this->assertSame('def456', $items[1]->external_id);
        $this->assertSame('Official expansion announcement', $items[1]->title);
        $this->assertSame('https://example.com/posts/expansion', $items[1]->body);
        $this->assertSame('link', $items[1]->metadata['post_kind']);
        $this->assertSame('https://example.com/posts/expansion', $items[1]->metadata['linked_url']);
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
    public function test_each_sort_order_requests_the_matching_public_json_path(string $sort, ?string $period, string $expectedUrl): void
    {
        Http::fake([
            $expectedUrl.'*' => Http::response($this->fixture('sample-listing.json'), 200, [
                'Content-Type' => 'application/json',
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
            'hot' => ['hot', null, 'https://www.reddit.com/r/pokemon/hot.json'],
            'new' => ['new', null, 'https://www.reddit.com/r/pokemon/new.json'],
            'top' => ['top', 'week', 'https://www.reddit.com/r/pokemon/top.json'],
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
            return str_starts_with($request->url(), 'https://www.reddit.com/r/pokemon/hot.json')
                && $request->hasHeader(
                    'User-Agent',
                    'Michicast/1.0 (+https://github.com/Michimono/michicast; reddit-source)',
                )
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_a_missing_or_private_subreddit_is_an_actionable_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/nope/hot.json*' => Http::response('Not Found', 404),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'nope',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'Subreddit r/nope was not found or is private. Check the name or whether the community is public.',
            $result->message,
        );
    }

    public function test_a_forbidden_subreddit_is_reported_as_missing_or_private(): void
    {
        Http::fake([
            'https://www.reddit.com/r/privateclub/hot.json*' => Http::response('Forbidden', 403),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'subreddit' => 'privateclub',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'Subreddit r/privateclub was not found or is private. Check the name or whether the community is public.',
            $result->message,
        );
    }

    public function test_a_rate_limited_response_is_not_retried_and_reports_transient_retry_after(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot.json*' => Http::response(
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
        $this->assertCount(1, $this->recordedUrlsMatching('https://www.reddit.com/r/pokemon/hot.json'));
    }

    public function test_an_unreachable_listing_is_a_structured_connection_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot.json*' => function () {
                throw new ConnectionException('Could not resolve host');
            },
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit listing could not be reached.', $result->message);
    }

    public function test_malformed_json_is_a_structured_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot.json*' => Http::response($this->fixture('broken.json'), 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit listing response could not be parsed.', $result->message);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The Reddit listing response could not be parsed.');

        $this->provider()->fetch($this->definition());
    }

    public function test_an_unexpected_payload_shape_is_a_structured_failure(): void
    {
        Http::fake([
            'https://www.reddit.com/r/pokemon/hot.json*' => Http::response(
                $this->fixture('unexpected-shape.json'),
                200,
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The Reddit listing response had an unexpected shape.', $result->message);
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
            'https://www.reddit.com/r/pokemon/hot.json',
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
            'https://www.reddit.com/r/quietsub/hot.json*' => Http::response(
                $this->fixture('empty-listing.json'),
                200,
                ['Content-Type' => 'application/json'],
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
            'https://www.reddit.com/r/'.$subreddit.'/'.$sort.'.json*' => Http::response(
                $this->fixture('sample-listing.json'),
                200,
                ['Content-Type' => 'application/json'],
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
