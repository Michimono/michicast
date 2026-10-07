<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\RegistrationMode;
use App\Models\AdminActionLog;
use App\Models\OrganizationInvitation;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_service_falls_back_to_config_when_no_row_is_stored(): void
    {
        config(['michicast.registration.mode' => 'invite_only', 'michicast.registration.daily_cap' => 7]);

        $settings = app(RegistrationSettings::class);

        $this->assertDatabaseEmpty('platform_settings');
        $this->assertSame(RegistrationMode::InviteOnly, $settings->mode());
        $this->assertSame(7, $settings->dailyCap());
        $this->assertFalse($settings->isStored());
    }

    public function test_a_stored_row_overrides_config(): void
    {
        config(['michicast.registration.mode' => 'open', 'michicast.registration.daily_cap' => 7]);

        app(RegistrationSettings::class)->update(RegistrationMode::Closed, null);

        $settings = app(RegistrationSettings::class);

        $this->assertSame(RegistrationMode::Closed, $settings->mode());
        $this->assertNull($settings->dailyCap());
        $this->assertTrue($settings->isStored());
    }

    public function test_open_mode_shows_the_form_and_registers(): void
    {
        config(['michicast.registration.mode' => 'open']);

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('Create account')
            ->assertSee('action="'.route('users.store').'"', false)
            ->assertDontSee('Registration is by invitation only.')
            ->assertDontSee('Registration is currently closed.');

        $this->post(route('users.store'), $this->payload('ada@example.test'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@example.test']);
    }

    public function test_invite_only_mode_explains_itself_and_refuses_uninvited_emails(): void
    {
        config(['michicast.registration.mode' => 'invite_only']);

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('Registration is by invitation only.')
            ->assertSee('action="'.route('users.store').'"', false);

        $this->post(route('users.store'), $this->payload('stranger@example.test'))
            ->assertSessionHasErrors(['email' => RegistrationSettings::REFUSAL_INVITE_ONLY]);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.test']);
    }

    public function test_closed_mode_shows_no_form_and_refuses_registration(): void
    {
        config(['michicast.registration.mode' => 'closed']);

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('Registration is currently closed.')
            ->assertDontSee('action="'.route('users.store').'"', false);

        $this->post(route('users.store'), $this->payload('ada@example.test'))
            ->assertSessionHasErrors(['email' => RegistrationSettings::REFUSAL_CLOSED]);

        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_in_invite_only_mode_an_invited_email_can_register_and_another_cannot(): void
    {
        config(['michicast.registration.mode' => 'invite_only']);

        OrganizationInvitation::factory()->forEmail('invited@example.test')->create();
        OrganizationInvitation::factory()->forEmail('expired@example.test')->expired()->create();
        OrganizationInvitation::factory()->forEmail('used@example.test')->accepted()->create();

        foreach (['someone@example.test', 'expired@example.test', 'used@example.test'] as $email) {
            $this->post(route('users.store'), $this->payload($email))
                ->assertSessionHasErrors(['email' => RegistrationSettings::REFUSAL_INVITE_ONLY]);

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }

        $this->assertGuest();

        $this->post(route('users.store'), $this->payload('Invited@Example.test'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'Invited@Example.test']);
    }

    public function test_invitation_acceptance_keeps_working_in_invite_only_mode(): void
    {
        config(['michicast.registration.mode' => 'invite_only']);

        $invitation = OrganizationInvitation::factory()
            ->forEmail('invited@example.test')
            ->withPlainToken('invite-token')
            ->create();

        $this->get(route('invitation-acceptances.edit', 'invite-token'))
            ->assertOk()
            ->assertSee('Create account');

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('value="invited@example.test"', false);

        $this->post(route('users.store'), $this->payload('invited@example.test'))
            ->assertRedirect(route('invitation-acceptances.edit', 'invite-token'));

        $this->patch(route('invitation-acceptances.update', 'invite-token'))
            ->assertRedirect(route('members.index', $invitation->organization));

        $this->assertNotNull($invitation->fresh()?->accepted_at);
    }

    public function test_the_daily_cap_closes_registration_once_reached(): void
    {
        config(['michicast.registration.mode' => 'open', 'michicast.registration.daily_cap' => 2]);

        User::factory()->create(['created_at' => now()->subDay()]);
        User::factory()->create();

        $this->post(route('users.store'), $this->payload('second@example.test'))
            ->assertRedirect(route('dashboard'));

        auth()->logout();

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('New sign-ups are paused for today.')
            ->assertDontSee('action="'.route('users.store').'"', false);

        $this->post(route('users.store'), $this->payload('third@example.test'))
            ->assertSessionHasErrors(['email' => RegistrationSettings::REFUSAL_CAP_REACHED]);

        $this->assertDatabaseMissing('users', ['email' => 'third@example.test']);

        $this->travel(1)->days();

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('action="'.route('users.store').'"', false);
    }

    public function test_the_login_page_and_header_hide_the_register_link_only_when_closed(): void
    {
        config(['michicast.registration.mode' => 'invite_only']);

        $this->get(route('session.create'))
            ->assertOk()
            ->assertSee('Need an account?')
            ->assertSee('href="'.route('users.create').'"', false);

        config(['michicast.registration.mode' => 'closed']);

        $this->get(route('session.create'))
            ->assertOk()
            ->assertDontSee('Need an account?')
            ->assertDontSee('href="'.route('users.create').'"', false);
    }

    public function test_the_admin_settings_page_shows_the_current_mode(): void
    {
        $admin = $this->admin();

        config(['michicast.registration.mode' => 'invite_only']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Registration mode')
            ->assertSee('MICHICAST_REGISTRATION=invite_only', false)
            ->assertSee('<option value="invite_only" selected>', false);
    }

    public function test_non_admins_get_404_on_the_settings_routes(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.settings.edit'))
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.settings.update'), ['mode' => 'closed', 'reason' => 'Abuse wave'])
            ->assertNotFound();

        $this->assertDatabaseEmpty('platform_settings');
    }

    public function test_an_admin_change_takes_effect_on_the_next_request_and_is_audited(): void
    {
        config(['michicast.registration.mode' => 'open', 'michicast.registration.daily_cap' => null]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.settings.update'), [
                'mode' => 'closed',
                'daily_cap' => '25',
                'reason' => 'Spam sign-ups from the public droplet',
            ])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('platform_settings', ['key' => PlatformSetting::REGISTRATION_MODE, 'value' => 'closed']);
        $this->assertDatabaseHas('platform_settings', ['key' => PlatformSetting::REGISTRATION_DAILY_CAP, 'value' => '25']);

        // RecordAdminSignIn also writes a signed_in row on the first admin request.
        $log = AdminActionLog::query()->where('action', AdminAction::RegistrationSettingsChanged)->sole();
        $setting = PlatformSetting::query()->where('key', PlatformSetting::REGISTRATION_MODE)->sole();

        $this->assertSame(AdminAction::RegistrationSettingsChanged, $log->action);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($setting->getMorphClass(), $log->subject_type);
        $this->assertSame($setting->id, $log->subject_id);
        $this->assertSame('Spam sign-ups from the public droplet', $log->reason);
        $this->assertEquals([
            'mode' => ['before' => 'open', 'after' => 'closed'],
            'daily_cap' => ['before' => null, 'after' => 25],
        ], $log->changes);

        auth()->logout();

        $this->get(route('users.create'))
            ->assertOk()
            ->assertSee('Registration is currently closed.');
    }

    public function test_an_unchanged_submission_writes_no_audit_entry(): void
    {
        config(['michicast.registration.mode' => 'open', 'michicast.registration.daily_cap' => null]);

        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.settings.edit'))
            ->patch(route('admin.settings.update'), ['mode' => 'open', 'reason' => 'No-op'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('error', 'Registration settings are unchanged.');

        $this->assertDatabaseMissing('admin_actions', ['action' => AdminAction::RegistrationSettingsChanged->value]);
        $this->assertDatabaseEmpty('platform_settings');
    }

    public function test_the_admin_change_requires_a_valid_mode_and_reason(): void
    {
        $this->actingAs($this->admin())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.settings.update'), ['mode' => 'everyone', 'daily_cap' => '0'])
            ->assertSessionHasErrors(['mode', 'daily_cap', 'reason']);

        $this->assertDatabaseMissing('admin_actions', ['action' => AdminAction::RegistrationSettingsChanged->value]);
        $this->assertDatabaseEmpty('platform_settings');
    }

    private function admin(): User
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        return User::factory()->create(['email' => 'admin@example.test']);
    }

    /**
     * @return array<string, string>
     */
    private function payload(string $email): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ];
    }
}
