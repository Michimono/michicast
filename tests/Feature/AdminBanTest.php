<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminBanTest extends TestCase
{
    use RefreshDatabase;

    public function test_ban_deletes_sessions_and_rotates_remember_token(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $user = User::factory()->create([
            'email' => 'target@example.test',
            'remember_token' => 'old-remember-token',
        ]);

        DB::table('sessions')->insert([
            [
                'id' => 'session-1',
                'user_id' => $user->id,
                'ip_address' => '203.0.113.1',
                'user_agent' => 'Agent/1',
                'payload' => 'payload-one',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'session-2',
                'user_id' => $user->id,
                'ip_address' => '203.0.113.2',
                'user_agent' => 'Agent/2',
                'payload' => 'payload-two',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.ban.store', $user), [
                'reason' => 'Abuse of the platform',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $user->refresh();

        $this->assertTrue($user->isBanned());
        $this->assertSame('Abuse of the platform', $user->ban_reason);
        $this->assertSame($admin->id, $user->banned_by);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_banned_authenticated_request_is_logged_out(): void
    {
        $user = User::factory()->banned()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('session.create'))
            ->assertSessionHas('error', 'Your session has ended. Please log in again.');

        $this->assertGuest();
    }

    public function test_login_for_banned_user_fails_with_generic_message(): void
    {
        $user = User::factory()->banned()->create([
            'email' => 'banned@example.test',
        ]);

        $banned = $this->from(route('session.create'))
            ->post(route('session.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $banned->assertSessionHasErrors([
            'email' => __('auth.failed'),
        ]);
        $this->assertGuest();

        $wrongPassword = $this->from(route('session.create'))
            ->post(route('session.store'), [
                'email' => $user->email,
                'password' => 'not-the-password',
            ]);

        $wrongPassword->assertSessionHasErrors([
            'email' => __('auth.failed'),
        ]);
        $this->assertGuest();
    }

    public function test_no_reset_mail_is_sent_to_banned_user_while_response_matches_normal(): void
    {
        Notification::fake();

        $active = User::factory()->create(['email' => 'active@example.test']);
        $banned = User::factory()->banned()->create(['email' => 'banned@example.test']);

        $activeResponse = $this->from(route('password-reset-links.create'))
            ->post(route('password-reset-links.store'), ['email' => $active->email]);

        Notification::assertSentTo($active, ResetPasswordNotification::class);

        $bannedResponse = $this->from(route('password-reset-links.create'))
            ->post(route('password-reset-links.store'), ['email' => $banned->email]);

        Notification::assertNotSentTo($banned, ResetPasswordNotification::class);

        $expectedSuccess = __(Password::ResetLinkSent);

        $activeResponse->assertRedirect(route('password-reset-links.create'))
            ->assertSessionHas('success', $expectedSuccess);
        $bannedResponse->assertRedirect(route('password-reset-links.create'))
            ->assertSessionHas('success', $expectedSuccess);
    }

    public function test_admin_cannot_ban_self_or_another_admin(): void
    {
        config(['michicast.platform_admin_emails' => [
            'admin@example.test',
            'other-admin@example.test',
        ]]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $otherAdmin = User::factory()->create(['email' => 'other-admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.users.show', $admin))
            ->post(route('admin.users.ban.store', $admin), [
                'reason' => 'Should not work',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $admin))
            ->assertSessionHas('error');

        $this->assertFalse($admin->fresh()->isBanned());

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.users.show', $otherAdmin))
            ->post(route('admin.users.ban.store', $otherAdmin), [
                'reason' => 'Should not work either',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $otherAdmin))
            ->assertSessionHas('error');

        $this->assertFalse($otherAdmin->fresh()->isBanned());
    }

    public function test_ban_and_unban_write_audit_entries_with_reason(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $user = User::factory()->create(['email' => 'target@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.ban.store', $user), [
                'reason' => 'Spam campaign',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::Banned->value,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'reason' => 'Spam campaign',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.users.ban.destroy', $user))
            ->assertRedirect(route('admin.users.show', $user));

        $user->refresh();
        $this->assertFalse($user->isBanned());
        $this->assertNull($user->ban_reason);
        $this->assertNull($user->banned_by);

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::Unbanned->value,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'reason' => 'Spam campaign',
        ]);
    }

    public function test_banned_user_cannot_accept_an_invitation(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();
        $banned = User::factory()->banned()->create(['email' => 'invitee@example.test']);
        $token = 'invitation-token';

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail($banned->email)
            ->role(Role::Viewer)
            ->withPlainToken($token)
            ->create();

        $this->actingAs($banned)
            ->patch(route('invitation-acceptances.update', $token))
            ->assertRedirect(route('session.create'));

        $this->assertGuest();
        $this->assertNull(OrganizationInvitation::query()->sole()->accepted_at);
        $this->assertNull($organization->roleFor($banned));
    }

    public function test_unban_allows_login_again(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $user = User::factory()->banned()->create([
            'email' => 'returning@example.test',
            'ban_reason' => 'Temporary',
        ]);

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.users.ban.destroy', $user))
            ->assertRedirect(route('admin.users.show', $user));

        auth()->logout();
        $this->flushSession();

        $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_access_admin_denies_banned_platform_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->banned()->create([
            'email' => 'admin@example.test',
            'ban_reason' => 'Compromised',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertRedirect(route('session.create'));

        $this->assertGuest();
    }

    public function test_banned_user_cannot_complete_password_reset(): void
    {
        $user = User::factory()->banned()->create(['email' => 'banned@example.test']);
        $token = Password::createToken($user);

        $this->put(route('passwords.update', $token), [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertTrue($user->fresh()->isBanned());
    }
}
