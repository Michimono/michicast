<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $project = Project::factory()->create();
        $organization = $project->organization;

        $this->get(route('projects.index', $organization))->assertRedirect(route('session.create'));
        $this->get(route('projects.create', $organization))->assertRedirect(route('session.create'));
        $this->get(route('projects.show', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->get(route('projects.edit', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->post(route('projects.store', $organization), ['name' => 'News'])->assertRedirect(route('session.create'));
        $this->patch(route('projects.update', [$organization, $project]), ['name' => 'News'])->assertRedirect(route('session.create'));
    }

    public function test_the_index_lists_only_projects_in_the_current_organization(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $here = Project::factory()->for($organization)->create(['name' => 'Pokemon TCGP News']);
        Project::factory()->create(['name' => 'Someone Else News']);

        $this->actingAs($user)
            ->get(route('projects.index', $organization))
            ->assertOk()
            ->assertSee('Pokemon TCGP News')
            ->assertDontSee('Someone Else News')
            ->assertSee(route('projects.show', [$organization, $here]), false)
            ->assertDontSee('New project');
    }

    public function test_an_editor_sees_the_create_control_on_the_index(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->get(route('projects.index', $organization))
            ->assertOk()
            ->assertSee('New project')
            ->assertSee('Nothing to chase yet');
    }

    public function test_an_editor_can_create_a_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $response = $this->actingAs($user)->post(route('projects.store', $organization), [
            'name' => 'Pokemon TCGP News',
            'description' => 'Pocket news.',
        ]);

        $project = $organization->projects()->sole();

        $response->assertRedirect(route('projects.show', [$organization, $project]));
        $response->assertSessionHas('success');

        $this->assertSame('Pokemon TCGP News', $project->name);
        $this->assertSame('pokemon-tcgp-news', $project->slug);
        $this->assertSame('Pocket news.', $project->description);
        $this->assertSame(ProjectStatus::Draft, $project->status);
    }

    public function test_a_viewer_cannot_create_a_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);

        $this->actingAs($user)
            ->get(route('projects.create', $organization))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('projects.store', $organization), ['name' => 'News'])
            ->assertForbidden();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_an_empty_description_is_stored_as_null(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('projects.store', $organization), [
                'name' => 'News',
                'description' => '   ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($organization->projects()->sole()->description);
    }

    public function test_a_slug_in_the_payload_is_ignored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('projects.store', $organization), [
                'name' => 'News',
                'slug' => 'hijacked',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('news', $organization->projects()->sole()->slug);
    }

    public function test_a_status_in_the_payload_is_ignored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('projects.store', $organization), [
                'name' => 'News',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::Draft, $organization->projects()->sole()->status);
    }

    public function test_two_organizations_may_reuse_the_same_slug(): void
    {
        [$firstUser, $first] = $this->memberHolding(Role::Editor);
        [$secondUser, $second] = $this->memberHolding(Role::Editor);

        $this->actingAs($firstUser)->post(route('projects.store', $first), ['name' => 'News']);
        $this->actingAs($secondUser)->post(route('projects.store', $second), ['name' => 'News']);

        $this->assertSame('news', $first->projects()->sole()->slug);
        $this->assertSame('news', $second->projects()->sole()->slug);
    }

    public function test_a_second_project_in_the_same_organization_gets_its_own_slug(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)->post(route('projects.store', $organization), ['name' => 'News']);
        $this->actingAs($user)->post(route('projects.store', $organization), ['name' => 'News']);

        $this->assertSame(
            ['news', 'news-2'],
            $organization->projects()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_a_name_without_sluggable_characters_still_gets_a_url_safe_slug(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('projects.store', $organization), ['name' => '!!!'])
            ->assertSessionHasNoErrors();

        $this->assertSame('project', $organization->projects()->sole()->slug);
    }

    public function test_an_editor_can_rename_a_project_without_changing_its_slug(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create([
            'name' => 'News',
            'slug' => 'news',
            'description' => 'Old copy.',
        ]);

        $this->actingAs($user)
            ->patch(route('projects.update', [$organization, $project]), [
                'name' => 'Morning News',
                'description' => 'New copy.',
            ])
            ->assertRedirect(route('projects.show', [$organization, $project]));

        $project->refresh();

        $this->assertSame('Morning News', $project->name);
        $this->assertSame('news', $project->slug);
        $this->assertSame('New copy.', $project->description);
    }

    public function test_a_viewer_cannot_edit_a_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create(['name' => 'News']);

        $this->actingAs($user)
            ->get(route('projects.edit', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('projects.update', [$organization, $project]), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('News', $project->refresh()->name);
    }

    public function test_every_member_can_read_a_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create([
            'name' => 'Pokemon TCGP News',
            'description' => 'Pocket news.',
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Pokemon TCGP News')
            ->assertSee('Pocket news.')
            ->assertDontSee('Edit project');
    }

    public function test_an_editor_is_offered_the_edit_screen(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee(route('projects.edit', [$organization, $project]), false);
    }

    public function test_a_member_of_another_organization_cannot_read_or_modify_a_project_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $project = Project::factory()->for($organization)->create(['name' => 'Secret Pipeline']);

        $this->actingAs($outsider)
            ->get(route('projects.show', [$organization, $project]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('projects.edit', [$organization, $project]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->patch(route('projects.update', [$organization, $project]), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Secret Pipeline', $project->refresh()->name);
    }

    public function test_a_project_of_another_organization_is_not_found_under_an_organization_the_user_owns(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner);
        $elsewhere = Project::factory()->create(['name' => 'Secret Pipeline']);

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $elsewhere]))
            ->assertNotFound();

        $this->actingAs($user)
            ->patch(route('projects.update', [$organization, $elsewhere]), ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Secret Pipeline', $elsewhere->refresh()->name);
    }

    public function test_the_name_is_required(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('projects.store', $organization), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_dangerous_name_and_description_are_escaped(): void
    {
        $name = '<script>alert("xss")</script>';
        $description = '<img src=x onerror=alert(1)>';
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create([
            'name' => $name,
            'description' => $description,
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee($name, false)
            ->assertSee($name)
            ->assertDontSee($description, false)
            ->assertSee($description);

        $this->actingAs($user)
            ->get(route('projects.index', $organization))
            ->assertOk()
            ->assertDontSee($name, false)
            ->assertSee($name);
    }

    public function test_the_projects_link_is_offered_when_an_organization_is_current(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee(route('organizations.show', $organization), false)
            ->assertSee('Projects');
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
