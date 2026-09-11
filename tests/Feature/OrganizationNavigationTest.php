<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_organization_page_shows_the_account_bar_and_the_organization_rail(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $response = $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk();

        $html = $response->getContent();

        $this->assertAnchorIsCurrent(route('organizations.show', $organization), $html);
        $this->assertStringContainsString(route('organizations.index'), $html);
        $this->assertStringContainsString(route('profile.edit'), $html);
        $this->assertStringContainsString(route('session.destroy'), $html);
        $this->assertStringContainsString(route('projects.index', $organization), $html);
        $this->assertStringContainsString(route('members.index', $organization), $html);
        $this->assertStringContainsString(route('organization-settings.edit', $organization), $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Projects', $html);
        $this->assertStringContainsString('Members', $html);
        $this->assertStringContainsString('Settings', $html);
        $this->assertStringContainsString('Switch organization', $html);
        $this->assertStringContainsString('Organization menu', $html);
        $this->assertStringContainsString('aria-label="Account"', $html);
        $this->assertStringContainsString('aria-label="Organization"', $html);
    }

    public function test_a_viewer_does_not_see_organization_settings_in_the_rail(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Viewer)->create();

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertDontSee(route('organization-settings.edit', $organization), false);
    }

    public function test_the_organizations_index_lists_only_memberships(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        Organization::factory()->withMember($user, Role::Editor)->create(['name' => 'Second Studio']);
        Organization::factory()->ownedBy(User::factory()->create())->create(['name' => 'Not Yours']);

        $this->actingAs($user)
            ->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('Michimono Labs')
            ->assertSee('Second Studio')
            ->assertDontSee('Not Yours')
            ->assertDontSee('id="organization-sidebar"', false);
    }

    public function test_a_member_of_another_organization_cannot_open_this_organization_or_its_projects(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create();
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($user)
            ->get(route('organizations.show', $foreign))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('projects.index', $foreign))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('members.index', $foreign))
            ->assertNotFound();
    }

    public function test_global_screens_keep_the_account_bar_and_omit_the_organization_rail(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Organizations')
            ->assertSee('Profile')
            ->assertSee('Log out')
            ->assertDontSee('id="organization-sidebar"', false)
            ->assertDontSee('Organization menu');
    }

    public function test_the_creation_screen_has_no_organization_rail_before_the_first_organization_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertDontSee('id="organization-sidebar"', false)
            ->assertDontSee('Organization menu');
    }

    private function assertAnchorIsCurrent(string $url, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="'.preg_quote($url, '/').'"[^>]*aria-current="page"/s',
            $html,
        );
    }
}
