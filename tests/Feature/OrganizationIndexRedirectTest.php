<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationIndexRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_without_organizations_is_sent_to_the_create_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('organizations.index'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_the_create_page_renders_for_them_without_a_redirect_loop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk();
    }

    public function test_a_member_sees_the_index(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Pokémon News']);

        $this->actingAs($user)
            ->get(route('organizations.index'))
            ->assertOk()
            ->assertSee($organization->name);
    }

    public function test_a_suspended_organization_still_counts(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Paused Org']);
        $organization->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('organizations.index'))
            ->assertOk()
            ->assertSee('Paused Org');
    }

    public function test_a_platform_admin_without_memberships_is_redirected_too(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);
        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->get(route('organizations.index'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_guests_still_go_to_login(): void
    {
        $this->get(route('organizations.index'))
            ->assertRedirect(route('session.create'));
    }
}
