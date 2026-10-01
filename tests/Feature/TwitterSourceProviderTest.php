<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Exceptions\SourceFetchFailedException;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\SourceDefinition;
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
        Http::assertSent(fn (Request $request) => $request->url() === 'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA');
    }

    public function test_requests_use_chrome_like_headers_and_soft_bootstrap(): void
    {
        $this->fakeProfile('NASA', 'sample-profile.html', guestCookie: 'guest_id=v1%3Atest; Domain=.twitter.com; Path=/');

        $this->assertTrue($this->provider()->testConnection($this->definition())->successful);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://twitter.com/NASA'
                && $request->hasHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36')
                && $request->hasHeader('Accept-Language', 'en-US,en;q=0.9')
                && $request->hasHeader('Sec-Fetch-Dest', 'document')
                && $request->hasHeader('Sec-Fetch-Mode', 'navigate')
                && $request->hasHeader('Sec-Fetch-Site', 'none')
                && $request->hasHeader('Sec-Fetch-User', '?1')
                && $request->hasHeader('Sec-CH-UA-Mobile', '?0')
                && $request->hasHeader('Sec-CH-UA-Platform', '"Windows"')
                && str_contains((string) $request->header('Sec-CH-UA')[0], 'Google Chrome')
                && str_contains((string) $request->header('Accept')[0], 'text/html');
        });

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA'
                && $request->hasHeader('Referer', 'https://twitter.com/NASA')
                && $request->hasHeader('Sec-Fetch-Site', 'same-site')
                && $request->hasHeader('Sec-Fetch-Dest', 'document')
                && $request->hasHeader('Sec-CH-UA-Mobile', '?0')
                && $request->hasHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36')
                && $request->hasHeader('Upgrade-Insecure-Requests', '1');
        });

        $this->assertCount(1, $this->recordedUrlsMatching('https://twitter.com/NASA'));
        $this->assertCount(1, $this->recordedUrlsMatching('syndication.twitter.com'));
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
            'https://twitter.com/NASA' => Http::response('<html></html>', 200),
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA' => function () {
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
            'https://twitter.com/NASA' => Http::response('<html></html>', 200),
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA' => Http::response(
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
        $this->assertCount(1, $this->recordedUrlsMatching('syndication.twitter.com'));
    }

    public function test_a_forbidden_response_is_an_actionable_failure(): void
    {
        Http::fake([
            'https://twitter.com/NASA' => Http::response('<html></html>', 200),
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA' => Http::response('Nope', 403),
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

    public function test_soft_bootstrap_failure_still_attempts_syndication(): void
    {
        Http::fake([
            'https://twitter.com/NASA' => function () {
                throw new ConnectionException('bootstrap down');
            },
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/NASA' => Http::response(
                $this->fixture('sample-profile.html'),
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertCount(1, $this->recordedUrlsMatching('syndication.twitter.com'));
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
        $bootstrapHeaders = ['Content-Type' => 'text/html; charset=utf-8'];

        if ($guestCookie !== null) {
            $bootstrapHeaders['Set-Cookie'] = $guestCookie;
        }

        Http::fake([
            'https://twitter.com/'.$handle => Http::response('<html></html>', 200, $bootstrapHeaders),
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/'.$handle => Http::response(
                $this->fixture($fixture),
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
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
