<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SourceFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_renders_one_tile_and_one_field_group_per_offered_type(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $xpath = $this->dom($this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project])));

        $tiles = $xpath->query('//input[@type="radio"][@name="type"][@data-source-type-option]');
        $values = array_map(fn (\DOMElement $node) => $node->getAttribute('value'), $this->elements($tiles));
        $this->assertSame(['rss', 'reddit', 'twitter', 'web_page'], $values);

        $checked = $xpath->query('//input[@data-source-type-option][@checked]');
        $this->assertSame(1, $checked->length);
        $this->assertSame('rss', $this->el($xpath, '//input[@data-source-type-option][@checked]')->getAttribute('value'));

        foreach ($values as $type) {
            $group = $this->el($xpath, '//fieldset[@data-source-type-fields="'.$type.'"]');
            $this->assertSame($type !== 'rss', $group->hasAttribute('disabled'), $type);
            $this->assertSame($type !== 'rss', $group->hasAttribute('hidden'), $type);
        }

        // No hidden type input: the checked radio posts the type.
        $this->assertSame(0, $xpath->query('//input[@type="hidden"][@name="type"]')->length);
        $this->assertSame(1, $xpath->query('//noscript//button[normalize-space(.)="Show settings"][@formmethod="GET"]')->length);
    }

    public function test_the_query_type_selects_its_tile_and_group(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $xpath = $this->dom($this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project, 'type' => 'web_page'])));

        $this->assertSame('web_page', $this->el($xpath, '//input[@data-source-type-option][@checked]')->getAttribute('value'));
        $this->assertFalse($this->el($xpath, '//fieldset[@data-source-type-fields="web_page"]')->hasAttribute('disabled'));
        $this->assertStringContainsString('Web page settings', $this->el($xpath, '//*[@data-source-settings-heading]')->textContent);
    }

    public function test_field_ids_are_unique_and_fields_carry_placeholders_required_and_optional_markers(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $response = $this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project]));
        $xpath = $this->dom($response);

        $ids = array_map(fn (\DOMElement $node) => $node->getAttribute('id'), $this->elements($xpath->query('//*[@id]')));
        $this->assertSame(array_unique($ids), $ids, 'Every id on the page must be unique.');

        $feed = $this->el($xpath, '//input[@name="config[feed_url]"]');
        $this->assertSame('source-rss-feed_url', $feed->getAttribute('id'));
        $this->assertSame('https://example.com/feed.xml', $feed->getAttribute('placeholder'));
        $this->assertTrue($feed->hasAttribute('required'));
        $this->assertSame(1, $xpath->query('//label[@for="source-rss-feed_url"]')->length);

        $subreddit = $this->el($xpath, '//input[@name="config[subreddit]"]');
        $this->assertTrue($subreddit->hasAttribute('required'));
        $this->assertSame('@Pokemon', $this->el($xpath, '//input[@name="config[handle]"]')->getAttribute('placeholder'));

        $body = $this->el($xpath, '//input[@name="config[body_selector]"]');
        $this->assertFalse($body->hasAttribute('required'));
        $this->assertStringContainsString('Body selector (optional)', $this->el($xpath, '//label[@for="source-web_page-body_selector"]')->textContent);
        $this->assertStringContainsString('Item limit (optional)', $this->el($xpath, '//label[@for="source-rss-item_limit"]')->textContent);

        // Reddit's two selects sit side by side; single fields span both columns.
        $sort = $this->el($xpath, '//select[@name="config[sort]"]/ancestor::div[contains(@class, "min-w-0")][1]');
        $this->assertStringNotContainsString('sm:col-span-2', $sort->getAttribute('class'));
        $subredditCell = $this->el($xpath, '//input[@name="config[subreddit]"]/ancestor::div[contains(@class, "min-w-0")][1]');
        $this->assertStringContainsString('sm:col-span-2', $subredditCell->getAttribute('class'));
    }

    public function test_a_rejected_submission_returns_to_its_type_with_old_input_and_aria(): void
    {
        [$user, $organization, $project] = $this->editorProject();
        $create = route('broadcast-sources.create', [$organization, $project]);

        $this->actingAs($user)
            ->from($create)
            ->post(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Pokémon subreddit',
                'type' => 'reddit',
                'config' => ['subreddit' => 'not valid!', 'sort' => 'new'],
            ])
            ->assertRedirect($create);

        $xpath = $this->dom($this->actingAs($user)->get($create));

        $this->assertSame('reddit', $this->el($xpath, '//input[@data-source-type-option][@checked]')->getAttribute('value'));
        $this->assertFalse($this->el($xpath, '//fieldset[@data-source-type-fields="reddit"]')->hasAttribute('disabled'));
        $this->assertSame('Pokémon subreddit', $this->el($xpath, '//input[@name="name"]')->getAttribute('value'));

        $subreddit = $this->el($xpath, '//input[@name="config[subreddit]"]');
        $this->assertSame('not valid!', $subreddit->getAttribute('value'));
        $this->assertSame('true', $subreddit->getAttribute('aria-invalid'));
        $this->assertStringContainsString('source-reddit-subreddit-error', $subreddit->getAttribute('aria-describedby'));
        $this->assertSame(1, $xpath->query('//*[@id="source-reddit-subreddit-error"]')->length);
        $this->assertSame('new', $this->el($xpath, '//select[@name="config[sort]"]/option[@selected]')->getAttribute('value'));

        // Other types' groups don't inherit the reddit values.
        $this->assertSame('', $this->el($xpath, '//input[@name="config[item_limit]"][@id="source-rss-item_limit"]')->getAttribute('value'));
    }

    public function test_the_preview_status_reflects_not_tested_complete_and_needs_attention(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $this->actingAs($user)
            ->get(route('broadcast-sources.create', [$organization, $project]))
            ->assertSee('Not tested')
            ->assertSee('Your preview will appear here');

        Http::fake([
            'example.com/feed.xml' => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item><title>Fresh Pokémon news</title><link>https://example.com/a</link><guid>a</guid><pubDate>Tue, 13 Oct 2026 10:00:00 GMT</pubDate></item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            'example.com/broken.xml' => Http::response('nope', 500),
        ]);

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Feed', 'type' => 'rss', 'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertOk()
            ->assertSee('Test complete')
            ->assertSee('Latest entry')
            ->assertSee('Fresh Pokémon news');

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Feed', 'type' => 'rss', 'config' => ['feed_url' => 'https://example.com/broken.xml'],
            ])
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('role="alert"', false);
    }

    public function test_edit_shows_the_type_settings_and_a_csp_safe_delete_confirmation(): void
    {
        [$user, $organization, $project] = $this->editorProject();
        $source = Source::factory()->for($project)->create([
            'name' => 'Pocket subreddit',
            'type' => 'reddit',
            'config' => ['subreddit' => 'pokemon', 'sort' => 'top', 'time_period' => 'week'],
        ]);

        $response = $this->actingAs($user)->get(route('sources.edit', [$organization, $source]))->assertOk();
        $xpath = $this->dom($response);

        $response->assertSee('Reddit settings')->assertSee('Reddit (public JSON)')->assertSee('Not tested');
        $this->assertSame('pokemon', $this->el($xpath, '//input[@name="config[subreddit]"]')->getAttribute('value'));
        $this->assertSame('week', $this->el($xpath, '//select[@name="config[time_period]"]/option[@selected]')->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//form[@data-confirm][.//button[normalize-space(.)="Delete source"]]')->length);
        $this->assertSame(0, $xpath->query('//*[@onsubmit or @onclick or @onchange or @style]')->length);
    }

    public function test_the_create_page_has_no_inline_handlers_or_styles(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $xpath = $this->dom($this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project])));

        $this->assertSame(0, $xpath->query('//*[@onsubmit or @onclick or @onchange or @style]')->length);
    }

    private function el(\DOMXPath $xpath, string $query): \DOMElement
    {
        $node = $xpath->query($query)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node, $query);

        return $node;
    }

    /**
     * @param  \DOMNodeList<\DOMNode>|false  $nodes
     * @return list<\DOMElement>
     */
    private function elements(\DOMNodeList|false $nodes): array
    {
        $this->assertNotFalse($nodes);

        return array_values(array_filter(iterator_to_array($nodes), fn ($node) => $node instanceof \DOMElement));
    }

    private function dom(TestResponse $response): \DOMXPath
    {
        $html = $response->assertOk()->getContent();

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($dom);
    }

    public function test_source_create_and_edit_headers_link_back_to_broadcast(): void
    {
        [$user, $organization, $project] = $this->editorProject();

        $this->assertBackToBroadcast($this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project]))->assertOk(), $project);

        $source = Source::factory()->for($project)->create([
            'type' => 'reddit',
            'config' => ['subreddit' => 'pokemon', 'sort' => 'top', 'time_period' => 'week'],
        ]);

        $this->assertBackToBroadcast($this->actingAs($user)->get(route('sources.edit', [$organization, $source]))->assertOk(), $project);
    }

    private function assertBackToBroadcast(TestResponse $response, Project $project): void
    {
        $link = $this->el($this->dom($response), '//a[@href="'.route('broadcasts.show', $project).'"][contains(., "Back to broadcast")]');
        $this->assertStringEndsWith('Back to broadcast', trim($link->textContent));
        $this->assertStringNotContainsString($project->name, $link->textContent);
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function editorProject(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();

        return [$user, $organization, Project::factory()->for($organization)->create()];
    }
}
