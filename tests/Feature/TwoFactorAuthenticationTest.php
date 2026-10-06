<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_start_enrollment_and_see_qr_and_secret(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.two-factor-authentication.store'))
            ->assertRedirect(route('profile.edit').'#two-factor');

        $user->refresh();

        $this->assertTrue($user->hasTwoFactorPending());
        $this->assertNotNull($user->two_factor_secret);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('role="img"', false)
            ->assertSee($user->two_factor_secret, false)
            ->assertSee('Confirm and enable');
    }

    public function test_confirmation_requires_a_valid_code_then_shows_recovery_codes_once(): void
    {
        $totp = new Totp;
        $user = User::factory()->create();
        $secret = $totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($user)
            ->post(route('profile.confirmed-two-factor-authentication.store'), [
                'code' => '000000',
            ])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->refresh()->hasTwoFactorEnabled());

        $response = $this->actingAs($user)
            ->post(route('profile.confirmed-two-factor-authentication.store'), [
                'code' => $totp->code($secret),
            ]);

        $response->assertRedirect(route('profile.edit').'#two-factor');
        $response->assertSessionHas('two_factor_recovery_codes');

        $codes = session('two_factor_recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);
        $this->assertTrue($user->refresh()->hasTwoFactorEnabled());

        $this->actingAs($user)
            ->withSession(['two_factor_recovery_codes' => $codes])
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee($codes[0], false);

        // pull() removes them, so a subsequent settings view does not show them again.
        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee($codes[0], false);
    }

    public function test_login_with_confirmed_two_factor_requires_the_challenge(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $totp->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor-challenge.create'));

        $this->assertGuest();
        $this->assertTrue(session()->has('login.id'));

        $this->get(route('dashboard'))
            ->assertRedirect(route('two-factor-challenge.create'));

        $this->get(route('two-factor-challenge.create'))->assertOk();

        $this->post(route('two-factor-challenge.store'), [
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();

        $this->post(route('two-factor-challenge.store'), [
            'code' => $totp->code($secret),
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_unenrolled_users_skip_the_challenge_after_password_login(): void
    {
        $user = User::factory()->create();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertFalse(session()->has('login.id'));
    }

    public function test_an_unenrolled_platform_admin_reaches_admin_after_password_confirmation(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->assertFalse($admin->hasTwoFactorEnabled());

        $this->actingAs($admin)
            ->get(route('admin.overview'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)
            ->from(route('password.confirm'))
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('admin.overview'));

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertDontSee('Enable two-factor', false)
            ->assertDontSee(route('profile.two-factor-authentication.store'), false);
    }

    public function test_recovery_codes_are_single_use(): void
    {
        $totp = new Totp;
        $codes = $totp->generateRecoveryCodes();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor-challenge.create'));

        $this->post(route('two-factor-challenge.store'), [
            'code' => $codes[0],
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertFalse(in_array($codes[0], $user->refresh()->two_factor_recovery_codes ?? [], true));

        auth()->logout();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->post(route('two-factor-challenge.store'), [
            'code' => $codes[0],
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_challenge_is_throttled_like_login(): void
    {
        $totp = new Totp;
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_recovery_codes' => $totp->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('two-factor-challenge.store'), [
                'code' => '000000',
            ])->assertRedirect();
        }

        $this->post(route('two-factor-challenge.store'), [
            'code' => '000000',
        ])->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_the_artisan_command_clears_two_factor_by_email(): void
    {
        $totp = new Totp;
        $user = User::factory()->create(['email' => 'locked@example.test']);
        $user->forceFill([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_recovery_codes' => $totp->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('michicast:clear-two-factor', ['email' => 'Locked@example.test'])
            ->assertSuccessful();

        $user->refresh();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_settings_rail_includes_two_factor_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('href="#two-factor"', false)
            ->assertSee('id="two-factor"', false)
            ->assertSee('>Two-factor authentication</h2>', false);
    }
}
