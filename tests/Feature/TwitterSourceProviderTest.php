<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Support\Mapping\Schemas\TwitterSourceSchema;
use App\Support\SourceDefinition;
use App\Support\SourceHealth;
use App\Support\SourceProviderRegistry;
use App\Support\TwitterSourceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class TwitterSourceProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_twitter_provider_is_registered_for_forms_and_resolution(): void
    {
        $registry = $this->app->make(SourceProviderRegistry::class);

        $this->assertTrue($registry->has('twitter'));
        $this->assertInstanceOf(TwitterSourceProvider::class, $registry->get('twitter'));
        $this->assertSame('Twitter / X (public profile)', $registry->options()['twitter']);
        $this->assertTrue($registry->capabilities('twitter')->supportsConnectionTest);

        $fields = $registry->configurationFields('twitter');

        $this->assertSame('handle', $fields[0]->name);
        $this->assertSame(ConfigurationFieldType::Text, $fields[0]->type);
        $this->assertStringContainsString('Best-effort', (string) $fields[0]->hint);
    }

    public function test_twitter_origin_schema_lists_only_populated_fields(): void
    {
        $schema = TwitterSourceSchema::make();
        $names = array_map(static fn ($field) => $field->name, $schema->fields);

        $this->assertSame(['body', 'link', 'published_at', 'author', 'media'], $names);
        $this->assertSame($names, array_keys(TwitterSourceSchema::sample()));
        $this->assertArrayNotHasKey('title', TwitterSourceSchema::sample());
        $this->assertArrayNotHasKey('categories', TwitterSourceSchema::sample());
    }

    public function test_an_x_dot_com_profile_page_is_parsed_without_syndication(): void
    {
        $this->fakeProfile('NASA', 'sample-x-profile.html');

        $items = $this->provider()->fetch($this->definition());

        $this->assertCount(3, $items);
        $this->assertSame('2105369076662960310', $items[0]->external_id);
        $this->assertSame('NASA', $items[0]->author);
        $this->assertSame('image', $items[0]->media[0]['type']);
        $this->assertTrue($items[2]->metadata['is_retweet']);
        $this->assertCount(0, $this->recordedUrlsMatching('syndication.twitter.com'));
    }

    public function test_a_public_profile_produces_normalized_content_items(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $project = Project::factory()->create();
        $items = $this->provider()->fetch($this->definition($project->id));

        $this->assertCount(3, $items);
        $this->assertFalse($items[0]->exists);
        $this->assertSame('ext:2105369076662960310', $items[0]->identity_key);
        $this->assertSame('We will be at the International Astronautical Congress Oct. 5-9: https://t.co/poaiD7cQ08 https://t.co/cjVJHdeiXu', $items[0]->title);
        $this->assertSame('NASA', $items[0]->author);
        $this->assertSame('https://x.com/NASA/status/2105369076662960310', $items[0]->canonical_url);
        $this->assertTrue(
            $items[0]->published_at?->equalTo(CarbonImmutable::parse('2026-09-30 18:47:58', 'UTC')),
        );
        $this->assertSame(['provider' => 'twitter', 'is_retweet' => false], $items[0]->metadata);
        $this->assertSame('image', $items[0]->media[0]['type']);
        $this->assertSame('https://pbs.twimg.com/media/sample.jpg', $items[0]->media[0]['url']);
        $this->assertTrue($items[2]->metadata['is_retweet']);
    }

    public function test_retweets_can_be_excluded(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $items = $this->provider()->fetch($this->definition(config: [
            'handle' => 'NASA',
            'include_retweets' => 'no',
        ]));

        $this->assertCount(2, $items);
        $this->assertFalse($items[0]->metadata['is_retweet']);
        $this->assertFalse($items[1]->metadata['is_retweet']);
    }

    public function test_a_repeated_fetch_persists_each_identity_only_once(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

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
            'external_id' => '2105369076662960310',
            'author' => 'NASA',
        ]);
    }

    public function test_connection_testing_reports_the_post_count(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertSame('Reached @NASA and found 3 public posts.', $result->message);
    }

    public function test_handles_are_accepted_with_or_without_at(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $result = $this->provider()->testConnection($this->definition(config: [
            'handle' => '@NASA',
        ]));

        $this->assertTrue($result->successful);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://x.com/NASA');
    }

    public function test_requests_use_chrome_like_headers_against_x_dot_com(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $this->assertTrue($this->provider()->testConnection($this->definition())->successful);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://x.com/NASA'
                && $request->hasHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36')
                && $request->hasHeader('Accept-Language', 'en-US,en;q=0.9')
                && $request->hasHeader('Sec-Fetch-Dest', 'document')
                && $request->hasHeader('Sec-Fetch-Mode', 'navigate')
                && $request->hasHeader('Sec-Fetch-Site', 'none')
                && $request->hasHeader('Sec-Fetch-User', '?1')
                && $request->hasHeader('Sec-CH-UA-Mobile', '?0')
                && $request->hasHeader('Sec-CH-UA-Platform', '"Windows"')
                && str_contains((string) $request->header('Sec-CH-UA')[0], 'Google Chrome')
                && str_contains((string) $request->header('Accept')[0], 'text/html')
                && $request->hasHeader('Upgrade-Insecure-Requests', '1');
        });

        $this->assertCount(1, $this->recordedUrlsMatching('https://x.com/NASA'));
        $this->assertCount(0, $this->recordedUrlsMatching('syndication.twitter.com'));
    }

    public function test_an_unknown_or_blocked_profile_is_a_structured_failure(): void
    {
        $this->fakeProfile('nope', 'missing-profile.html');

        $result = $this->provider()->testConnection($this->definition(config: [
            'handle' => 'nope',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'No public posts were found for @nope. The account may not exist, may be protected, or X may be blocking unauthenticated reads.',
            $result->message,
        );
    }

    public function test_an_empty_known_profile_succeeds_with_zero_posts(): void
    {
        $this->fakeProfile('quietuser', 'empty-profile.html');

        $result = $this->provider()->testConnection($this->definition(config: [
            'handle' => 'quietuser',
        ]));

        $this->assertTrue($result->successful);
        $this->assertSame('Reached @quietuser and found 0 public posts.', $result->message);
    }

    public function test_an_unreachable_profile_is_a_structured_connection_failure(): void
    {
        Http::fake([
            'https://x.com/NASA' => function () {
                throw new ConnectionException('Could not resolve host');
            },
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The X profile could not be reached.', $result->message);
    }

    public function test_a_rate_limited_response_is_not_retried_and_reports_retry_after(): void
    {
        Http::fake([
            'https://x.com/NASA' => Http::response(
                'Slow down',
                429,
                ['Retry-After' => '120'],
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame(
            'X rate-limited the request (HTTP 429). Wait 2 minutes and try again once.',
            $result->message,
        );
        $this->assertCount(1, $this->recordedUrlsMatching('https://x.com/NASA'));
    }

    public function test_a_forbidden_response_is_an_actionable_failure(): void
    {
        Http::fake([
            'https://x.com/NASA' => Http::response('Nope', 403),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame(
            'X refused access (HTTP 403). Unauthenticated profile reads are often blocked; try again later or use another source.',
            $result->message,
        );
    }

    public function test_malformed_html_is_a_structured_failure(): void
    {
        $this->fakeProfile('NASA', 'broken.html');

        $result = $this->provider()->testConnection($this->definition());

        $this->assertFalse($result->successful);
        $this->assertSame('The X timeline response could not be parsed.', $result->message);

        $this->expectException(SourceFetchFailedException::class);
        $this->expectExceptionMessage('The X timeline response could not be parsed.');

        $this->provider()->fetch($this->definition());
    }

    public function test_the_item_limit_keeps_only_the_first_entries(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html');

        $items = $this->provider()->fetch($this->definition(config: [
            'handle' => 'NASA',
            'item_limit' => 1,
        ]));

        $this->assertCount(1, $items);
        $this->assertSame('2105369076662960310', $items[0]->external_id);
    }

    public function test_configuration_is_validated_against_the_provider_rules(): void
    {
        $rules = $this->app->make(SourceProviderRegistry::class)->configurationRules('twitter');

        $this->assertTrue(Validator::make(['handle' => 'NASA'], $rules)->passes());
        $this->assertTrue(Validator::make(['handle' => '@NASA'], $rules)->passes());
        $this->assertTrue(Validator::make(['handle' => 'bad handle!'], $rules)->fails());
        $this->assertTrue(Validator::make(['handle' => ''], $rules)->fails());
    }

    public function test_a_404_profile_is_reported_as_missing(): void
    {
        Http::fake([
            'https://x.com/nope' => Http::response('missing', 404),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: [
            'handle' => 'nope',
        ]));

        $this->assertFalse($result->successful);
        $this->assertSame(
            'No public posts were found for @nope. The account may not exist, may be protected, or X may be blocking unauthenticated reads.',
            $result->message,
        );
    }

    public function test_the_current_x_page_format_is_read_from_its_embedded_json(): void
    {
        $this->fakeProfile('PokemonTCGP', 'x-profile-2026-10.html');

        $items = $this->provider()->fetch($this->definition(config: ['handle' => 'PokemonTCGP']));

        $this->assertCount(4, $items);
        // Newest first, so the older pinned post does not jump the queue.
        $this->assertSame('2113389974385033612', $items[0]->external_id);
        $this->assertSame('Pokémon TCG Pocket', $items[0]->author);
        $this->assertSame('https://x.com/PokemonTCGP/status/2113389974385033612', $items[0]->canonical_url);
        $this->assertTrue($items[0]->published_at?->equalTo(CarbonImmutable::createFromTimestampMs(1792706409000)));
        $this->assertCount(3, $items[0]->media);
        $this->assertSame('image', $items[0]->media[0]['type']);
        $this->assertSame('2112529716330545392', $items[3]->external_id);
        $this->assertSame('video', $items[3]->media[0]['type']);
        $this->assertTrue($items[2]->metadata['is_retweet']);
        $this->assertCount(0, $this->recordedUrlsMatching('syndication.twitter.com'));
        $this->assertNull(app(SourceHealth::class)->current('twitter'));
    }

    public function test_an_unreadable_page_falls_back_to_the_embed_and_flags_admins(): void
    {
        Http::fake([
            'https://x.com/PokemonTCGP' => Http::response($this->fixture('x-profile-unreadable.html'), 200),
            'https://syndication.twitter.com/*' => Http::response($this->fixture('sample-profile.html'), 200),
        ]);

        $items = $this->provider()->fetch($this->definition(config: ['handle' => 'PokemonTCGP']));

        $this->assertCount(3, $items);
        $this->assertCount(1, $this->recordedUrlsMatching('syndication.twitter.com'));

        $flag = app(SourceHealth::class)->current('twitter');
        $this->assertNotNull($flag);
        $this->assertStringContainsString('fallback embed', $flag['message']);
        $this->assertSame(1038, $flag['context']['post_count']);
    }

    public function test_an_unreadable_page_is_a_failure_not_zero_posts(): void
    {
        Http::fake([
            'https://x.com/PokemonTCGP' => Http::response($this->fixture('x-profile-unreadable.html'), 200),
            'https://syndication.twitter.com/*' => Http::response('Rate limit exceeded', 429),
        ]);

        $result = $this->provider()->testConnection($this->definition(config: ['handle' => 'PokemonTCGP']));

        $this->assertFalse($result->successful);
        $this->assertSame(
            "@PokemonTCGP has public posts, but Michicast couldn't read them. X has probably changed its page format; platform admins have been notified.",
            $result->message,
        );
        $this->assertStringContainsString('could not read any', (string) app(SourceHealth::class)->current('twitter')['message']);
    }

    public function test_a_normal_read_clears_an_earlier_flag(): void
    {
        app(SourceHealth::class)->flag('twitter', 'Broken earlier.');
        $this->fakeProfile('PokemonTCGP', 'x-profile-2026-10.html');

        $this->provider()->fetch($this->definition(config: ['handle' => 'PokemonTCGP']));

        $this->assertNull(app(SourceHealth::class)->current('twitter'));
    }

    public function test_admins_see_flagged_sources_on_the_overview(): void
    {
        app(SourceHealth::class)->flag('twitter', 'X profile pages show posts but Michicast could not read any.', ['handle' => 'PokemonTCGP']);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        config(['michicast.platform_admin_emails' => ['admin@example.com']]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('Source health')
            ->assertSee('X profile pages show posts but Michicast could not read any.')
            ->assertSee('@PokemonTCGP');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function definition(int $projectId = 1, int $sourceId = 12, array $config = []): SourceDefinition
    {
        return new SourceDefinition(
            'twitter',
            $config === [] ? ['handle' => 'NASA'] : $config,
            $projectId,
            $sourceId,
        );
    }

    private function provider(): TwitterSourceProvider
    {
        return $this->app->make(TwitterSourceProvider::class);
    }

    private function fakeProfile(string $handle, string $fixture, ?string $guestCookie = null): void
    {
        $headers = ['Content-Type' => 'text/html; charset=utf-8'];

        if ($guestCookie !== null) {
            $headers['Set-Cookie'] = $guestCookie;
        }

        Http::fake([
            'https://x.com/'.$handle => Http::response(
                $this->fixture($fixture),
                200,
                $headers,
            ),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/twitter/'.$name));
    }

    /**
     * @return list<string>
     */
    private function recordedUrlsMatching(string $needle): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_contains($url, $needle))
            ->values()
            ->all();
    }
}
