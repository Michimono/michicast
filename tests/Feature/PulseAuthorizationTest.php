<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PulseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_pulse_defers_to_access_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->assertTrue(Gate::forUser($admin)->allows('viewPulse'));
        $this->assertTrue(Gate::forUser($member)->denies('viewPulse'));
    }

    public function test_admin_pulse_is_404_for_non_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/pulse')
            ->assertNotFound();
    }

    public function test_admin_pulse_is_200_for_a_password_confirmed_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/pulse')
            ->assertOk();
    }

    public function test_legacy_pulse_path_is_404(): void
    {
        $this->get('/pulse')->assertNotFound();
    }

    public function test_admin_must_confirm_password_before_pulse(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->get('/admin/pulse')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_pulse_link_is_in_the_admin_sidebar(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('href="'.route('pulse').'"', false)
            ->assertSee('>Pulse</', false);
    }

    public function test_normal_pages_do_not_include_livewire_assets(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('/livewire/', $html);
        $this->assertDoesNotMatchRegularExpression('/livewire\.(?:csp\.)?(?:min\.)?js/i', $html);
        $this->assertStringNotContainsString('data-navigate-once', $html);
    }
}
