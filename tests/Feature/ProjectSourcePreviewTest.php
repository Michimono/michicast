<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectSourcePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_editor_can_preview_the_latest_rss_entry_before_saving(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->from(route('broadcast-sources.create', [$organization, $project, 'type' => 'rss']))
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Pocket news',
                'type' => 'rss',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertOk()
            ->assertSee('Second post')
            ->assertSee('https://example.com/posts/2', false)
            ->assertSee('Another entry.')
            ->assertDontSee('First post');

        $this->assertSame(0, $project->sources()->count());
        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_an_unreachable_feed_shows_an_actionable_error(): void
    {
        Http::fake([
            'https://example.com/missing.xml' => Http::response('Gone', 404),
        ]);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Pocket news',
                'type' => 'rss',
                'config' => ['feed_url' => 'https://example.com/missing.xml'],
            ])
            ->assertOk()
            ->assertSee('The feed returned HTTP 404.');

        $this->assertSame(0, $project->sources()->count());
    }

    public function test_preview_rejects_an_invalid_feed_url(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->from(route('broadcast-sources.create', [$organization, $project, 'type' => 'rss']))
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Pocket news',
                'type' => 'rss',
                'config' => ['feed_url' => 'not-a-url'],
            ])
            ->assertRedirect(route('broadcast-sources.create', [$organization, $project, 'type' => 'rss']))
            ->assertSessionHasErrors('config.feed_url');
    }

    public function test_an_editor_can_add_an_rss_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $response = $this->actingAs($user)->post(route('broadcast-sources.store', [$organization, $project]), [
            'name' => 'Pocket news feed',
            'type' => 'rss',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);

        $source = $project->sources()->sole();

        $response->assertRedirect(route('broadcasts.show', [$organization, $project]));
        $response->assertSessionHas('success', 'The source has been added.');

        $this->assertSame('Pocket news feed', $source->name);
        $this->assertSame('rss', $source->type);
        $this->assertSame('https://example.com/feed.xml', $source->config['feed_url']);
    }

    public function test_creating_an_rss_source_rejects_invalid_input(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $project]), [
                'name' => '',
                'type' => 'rss',
                'config' => ['feed_url' => 'not-a-url'],
            ])
            ->assertSessionHasErrors(['name', 'config.feed_url']);

        $this->assertSame(0, $project->sources()->count());
    }

    public function test_a_viewer_cannot_preview_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Pocket news',
                'type' => 'rss',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertForbidden();
    }

    public function test_source_previews_are_rate_limited(): void
    {
        Http::fake([
            'https://example.com/feed.xml' => Http::response($this->fixture('sample.rss.xml'), 200),
        ]);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $payload = [
            'name' => 'Pocket news',
            'type' => 'rss',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post(route('broadcast-source-previews.store', [$organization, $project]), $payload)
                ->assertOk();
        }

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), $payload)
            ->assertTooManyRequests();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/feeds/'.$name));
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
