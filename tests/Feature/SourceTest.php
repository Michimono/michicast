<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $source = Source::factory()->create();
        $project = $source->project;
        $organization = $project->organization;

        $this->get(route('broadcasts.show', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->get(route('broadcast-sources.create', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->post(route('broadcast-sources.store', [$organization, $project]), [])->assertRedirect(route('session.create'));
        $this->get(route('sources.edit', [$organization, $source]))->assertRedirect(route('session.create'));
        $this->patch(route('sources.update', [$organization, $source]), [])->assertRedirect(route('session.create'));
    }

    public function test_the_index_lists_the_sources_of_this_project_only(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create(['name' => 'Pocket news feed']);
        Source::factory()->for(Project::factory()->for($organization))->create(['name' => 'Another project feed']);
        Source::factory()->create(['name' => 'Someone else feed']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Pocket news feed')
            ->assertDontSee('Another project feed')
            ->assertDontSee('Someone else feed')
            ->assertDontSee('New source');
    }

    public function test_the_create_screen_renders_the_fields_the_chosen_type_declares(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcast-sources.create', [$organization, $project, 'type' => 'rss']))
            ->assertOk()
            ->assertSee('RSS / Atom feed')
            ->assertDontSee('Fake source')
            ->assertSee('Feed URL')
            ->assertSee('config[feed_url]', false)
            ->assertSee('Enter a feed URL and press Test to see the latest entry here.')
            ->assertDontSee('Show settings');
    }

    public function test_an_editor_can_add_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $response = $this->actingAs($user)->post(route('broadcast-sources.store', [$organization, $project]), [
            'name' => 'Pocket news feed',
            'type' => 'fake',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);

        $source = $project->sources()->sole();

        $response->assertRedirect(route('broadcasts.show', [$organization, $project]));
        $response->assertSessionHas('success', 'The source has been added.');

        $this->assertSame('Pocket news feed', $source->name);
        $this->assertSame('fake', $source->type);
        $this->assertSame(['feed_url' => 'https://example.com/feed.xml'], $source->config);
        $this->assertTrue($source->enabled);
        $this->assertTrue($source->isRunnable());
    }

    public function test_configuration_that_fails_the_provider_rules_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Pocket news feed',
                'type' => 'fake',
                'config' => ['feed_url' => 'not-a-url'],
            ])
            ->assertSessionHasErrors('config.feed_url');

        $this->assertSame(0, $project->sources()->count());
    }

    public function test_a_type_no_provider_answers_for_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Pocket news feed',
                'type' => 'carrier-pigeon',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, $project->sources()->count());
    }

    public function test_settings_the_type_does_not_declare_are_not_stored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)->post(route('broadcast-sources.store', [$organization, $project]), [
            'name' => 'Pocket news feed',
            'type' => 'fake',
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'admin' => 'true',
            ],
        ]);

        $this->assertSame(
            ['feed_url' => 'https://example.com/feed.xml'],
            $project->sources()->sole()->config,
        );
    }

    public function test_an_editor_can_change_a_name_and_configuration(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization, ['name' => 'Pocket news feed']);

        $this->actingAs($user)
            ->get(route('sources.edit', [$organization, $source]))
            ->assertOk()
            ->assertSee('Pocket news feed')
            ->assertSee('https://example.com/feed.xml', false);

        $this->actingAs($user)
            ->patch(route('sources.update', [$organization, $source]), [
                'name' => 'Pocket news RSS',
                'config' => ['feed_url' => 'https://example.com/other.xml'],
            ])
            ->assertRedirect(route('sources.edit', [$organization, $source]))
            ->assertSessionHas('success', 'The source has been updated.');

        $source->refresh();

        $this->assertSame('Pocket news RSS', $source->name);
        $this->assertSame(['feed_url' => 'https://example.com/other.xml'], $source->config);
    }

    public function test_an_edit_that_fails_the_provider_rules_keeps_the_stored_configuration(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization);

        $this->actingAs($user)
            ->patch(route('sources.update', [$organization, $source]), [
                'name' => 'Pocket news RSS',
                'config' => ['feed_url' => 'not-a-url'],
            ])
            ->assertSessionHasErrors('config.feed_url');

        $this->assertSame(['feed_url' => 'https://example.com/feed.xml'], $source->refresh()->config);
    }

    public function test_a_viewer_cannot_add_or_change_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $source = $this->sourceIn($organization);
        $project = $source->project;

        $this->actingAs($user)->get(route('broadcast-sources.create', [$organization, $project]))->assertForbidden();
        $this->actingAs($user)->post(route('broadcast-sources.store', [$organization, $project]), [
            'name' => 'Pocket news feed',
            'type' => 'fake',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ])->assertForbidden();
        $this->actingAs($user)->get(route('sources.edit', [$organization, $source]))->assertForbidden();
        $this->actingAs($user)->patch(route('sources.update', [$organization, $source]), [
            'name' => 'Renamed',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ])->assertForbidden();
    }

    public function test_a_source_of_another_organization_is_out_of_reach(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $elsewhere = Source::factory()->create(['name' => 'Someone else feed']);

        $this->actingAs($user)->get(route('sources.edit', [$organization, $elsewhere]))->assertNotFound();
        $this->actingAs($user)->patch(route('sources.update', [$organization, $elsewhere]), [
            'name' => 'Taken over',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ])->assertNotFound();

        $this->assertSame('Someone else feed', $elsewhere->refresh()->name);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sourceIn(Organization $organization, array $attributes = []): Source
    {
        return Source::factory()
            ->for(Project::factory()->for($organization))
            ->create([
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
                ...$attributes,
            ]);
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
