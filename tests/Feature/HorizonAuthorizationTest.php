<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_horizon_defers_to_access_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($member)->denies('viewHorizon'));
    }

    public function test_admin_horizon_is_404_for_non_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/horizon')
            ->assertNotFound();
    }

    public function test_admin_horizon_is_200_for_a_password_confirmed_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/horizon')
            ->assertOk();
    }

    public function test_admin_horizon_api_stats_is_404_for_non_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/horizon/api/stats')
            ->assertNotFound();
    }

    public function test_legacy_horizon_path_is_404(): void
    {
        $this->get('/horizon')->assertNotFound();
    }

    public function test_admin_must_confirm_password_before_horizon(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->get('/admin/horizon')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_horizon_link_is_in_the_admin_sidebar(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('href="'.route('horizon.index').'"', false)
            ->assertSee('>Horizon</', false);
    }
}
