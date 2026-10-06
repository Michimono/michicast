<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_invite_someone_by_email(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);

        $response = $this->actingAs($owner)->post(route('invitations.store', $organization), [
            'email' => 'Ada@example.test',
            'role' => Role::Editor->value,
        ]);

        $response->assertRedirect(route('members.index', $organization));
        $response->assertSessionHas('success', 'Invitation sent.');

        $invitation = OrganizationInvitation::query()->sole();

        $this->assertSame($organization->getKey(), $invitation->organization_id);
        $this->assertSame('ada@example.test', $invitation->email);
        $this->assertSame(Role::Editor, $invitation->role);
        $this->assertTrue($invitation->isPending());

        Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use ($invitation): bool {
            return $mail->queue === 'mail'
                && $mail->hasTo('ada@example.test')
                && $mail->invitation->is($invitation)
                && $mail->hasSubject('Join Michimono Labs');
        });
    }

    public function test_guests_cannot_send_or_cancel_invitations(): void
    {
        $invitation = OrganizationInvitation::factory()->create();
        $organization = $invitation->organization;

        $this->post(route('invitations.store', $organization), [
            'email' => 'ada@example.test',
            'role' => Role::Viewer->value,
        ])->assertRedirect(route('session.create'));

        $this->delete(route('invitations.destroy', [$organization, $invitation]))
            ->assertRedirect(route('session.create'));
    }

    public function test_the_invitation_form_is_offered_only_to_administrators(): void
    {
        [$owner, $ownersOrganization] = $this->memberHolding(Role::Owner);
        [$editor, $editorsOrganization] = $this->memberHolding(Role::Editor);

        $this->actingAs($owner)
            ->get(route('members.index', $ownersOrganization))
            ->assertOk()
            ->assertSee('Send invitation');

        $this->actingAs($editor)
            ->get(route('members.index', $editorsOrganization))
            ->assertOk()
            ->assertDontSee('Send invitation');
    }

    public function test_an_invitation_requires_an_email_and_a_role(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [])
            ->assertSessionHasErrors(['email', 'role']);

        Mail::assertNothingOutgoing();
    }

    public function test_inviting_an_address_that_already_has_an_account_still_only_sends_the_invitation(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $existing = User::factory()->create(['email' => 'ada@example.test']);

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'ada@example.test',
                'role' => Role::Viewer->value,
            ])
            ->assertSessionHas('success', 'Invitation sent.');

        $this->assertTrue($existing->memberships()->doesntExist());
        Mail::assertQueued(OrganizationInvitationMail::class, 'ada@example.test');
    }

    public function test_inviting_a_current_member_is_rejected_without_sending_mail(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = User::factory()->create(['email' => 'ada@example.test']);
        $organization->members()->create(['user_id' => $member->getKey(), 'role' => Role::Viewer]);

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'Ada@example.test',
                'role' => Role::Editor->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('organization_invitations', 0);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_a_non_administrator_cannot_invite(Role $role): void
    {
        Mail::fake();

        [$user, $organization] = $this->memberHolding($role);

        $this->actingAs($user)
            ->post(route('invitations.store', $organization), [
                'email' => 'ada@example.test',
                'role' => Role::Viewer->value,
            ])
            ->assertForbidden();

        Mail::assertNothingOutgoing();
    }

    public function test_an_existing_user_can_accept_an_invitation(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create(['email' => 'ada@example.test']);
        $token = 'invitation-token';

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->role(Role::Editor)
            ->withPlainToken($token)
            ->create();

        $this->actingAs($invitee)
            ->get(route('invitation-acceptances.edit', $token))
            ->assertOk()
            ->assertSee('Michimono Labs')
            ->assertSee('Editor');

        $this->actingAs($invitee)
            ->patch(route('invitation-acceptances.update', $token))
            ->assertRedirect(route('members.index', $organization))
            ->assertSessionHas('success');

        $this->assertSame(Role::Editor, $organization->fresh()->roleFor($invitee));
        $this->assertNotNull(OrganizationInvitation::query()->sole()->accepted_at);
    }

    public function test_an_existing_user_logs_in_from_the_invitation_and_accepts(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create([
            'email' => 'ada@example.test',
            'password' => 'password',
        ]);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->role(Role::Editor)
            ->withPlainToken('invitation-token')
            ->create();

        $this->get(route('invitation-acceptances.edit', 'invitation-token'))->assertOk();

        $this->get(route('session.create'))
            ->assertOk()
            ->assertSee('value="ada@example.test"', false);

        $this->post(route('session.store'), [
            'email' => 'ada@example.test',
            'password' => 'password',
        ])->assertRedirect(route('invitation-acceptances.edit', 'invitation-token'));

        $this->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertRedirect(route('members.index', $organization));

        $this->assertSame(Role::Editor, $organization->fresh()->roleFor($invitee));
    }

    public function test_a_guest_is_sent_back_to_the_invitation_instead_of_accepting(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create(['email' => 'ada@example.test']);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('invitation-token')
            ->create();

        $this->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertRedirect(route('invitation-acceptances.edit', 'invitation-token'));

        $this->assertNull($organization->fresh()->roleFor($invitee));
        $this->assertNull(OrganizationInvitation::query()->sole()->accepted_at);
    }

    public function test_a_new_user_registers_then_accepts_the_invitation(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->role(Role::Viewer)
            ->withPlainToken('invitation-token')
            ->create();

        $this->get(route('invitation-acceptances.edit', 'invitation-token'))
            ->assertOk()
            ->assertSee(route('users.create'), false)
            ->assertSee(route('session.create'), false);

        $this->assertGuest();

        $this->post(route('users.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ])->assertRedirect(route('invitation-acceptances.edit', 'invitation-token'));

        $this->assertAuthenticated();

        $this->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertRedirect(route('members.index', $organization));

        $user = User::whereEmail('ada@example.test')->sole();

        $this->assertSame(Role::Viewer, $organization->fresh()->roleFor($user));
    }

    public function test_a_guest_sees_the_same_acceptance_screen_whether_or_not_the_address_has_an_account(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        User::factory()->create(['email' => 'ada@example.test']);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('known-user')
            ->create();

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('new@example.test')
            ->withPlainToken('unknown-user')
            ->create();

        $known = $this->get(route('invitation-acceptances.edit', 'known-user'));
        $unknown = $this->get(route('invitation-acceptances.edit', 'unknown-user'));

        $known->assertOk()->assertSee('Log in')->assertSee('Create account');
        $unknown->assertOk()->assertSee('Log in')->assertSee('Create account');
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create(['email' => 'ada@example.test']);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('invitation-token')
            ->expired()
            ->create();

        $this->actingAs($invitee)
            ->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertRedirect(route('invitation-acceptances.edit', 'invitation-token'))
            ->assertSessionHas('error', 'This invitation has expired.');

        $this->assertNull($organization->fresh()->roleFor($invitee));
    }

    public function test_a_used_invitation_cannot_be_accepted_again(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create(['email' => 'ada@example.test']);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('invitation-token')
            ->accepted()
            ->create();

        $this->actingAs($invitee)
            ->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertSessionHas('error', 'This invitation has already been used.');

        $this->assertNull($organization->fresh()->roleFor($invitee));
    }

    public function test_a_cancelled_invitation_cannot_be_accepted(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $invitee = User::factory()->create(['email' => 'ada@example.test']);
        $invitation = OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('invitation-token')
            ->create();

        $this->actingAs($owner)
            ->delete(route('invitations.destroy', [$organization, $invitation]))
            ->assertRedirect(route('members.index', $organization));

        $this->actingAs($invitee)
            ->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertNotFound();
    }

    public function test_a_signed_in_user_cannot_accept_an_invitation_sent_to_someone_else(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $stranger = User::factory()->create(['email' => 'grace@example.test']);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->withPlainToken('invitation-token')
            ->create();

        $this->actingAs($stranger)
            ->patch(route('invitation-acceptances.update', 'invitation-token'))
            ->assertSessionHas('error');

        $this->assertNull($organization->fresh()->roleFor($stranger));
    }

    public function test_a_member_of_another_organization_cannot_cancel_an_invitation_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);

        $invitation = OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->create();

        $this->actingAs($outsider)
            ->delete(route('invitations.destroy', [$organization, $invitation]))
            ->assertNotFound();

        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->getKey()]);
    }

    public function test_an_editor_cannot_cancel_an_invitation(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $editor = User::factory()->create();
        $organization->members()->create(['user_id' => $editor->getKey(), 'role' => Role::Editor]);

        $invitation = OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->create();

        $this->actingAs($editor)
            ->delete(route('invitations.destroy', [$organization, $invitation]))
            ->assertForbidden();

        $this->assertDatabaseHas('organization_invitations', ['id' => $invitation->getKey()]);
    }

    public function test_an_unknown_invitation_token_is_not_found(): void
    {
        $this->get(route('invitation-acceptances.edit', 'missing'))->assertNotFound();
    }

    public function test_a_second_invite_to_the_same_address_replaces_the_pending_one(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);

        $this->actingAs($owner)->post(route('invitations.store', $organization), [
            'email' => 'ada@example.test',
            'role' => Role::Viewer->value,
        ]);

        $this->actingAs($owner)->post(route('invitations.store', $organization), [
            'email' => 'ada@example.test',
            'role' => Role::Editor->value,
        ])->assertSessionHasNoErrors();

        $invitation = OrganizationInvitation::query()->sole();

        $this->assertSame(Role::Editor, $invitation->role);
        Mail::assertQueued(OrganizationInvitationMail::class, 2);
    }

    public function test_the_invitation_mail_names_the_organization_role_and_accept_url(): void
    {
        $invitation = OrganizationInvitation::factory()->make(['role' => Role::Editor]);
        $invitation->setRelation('organization', Organization::factory()->make(['name' => 'Michimono Labs']));
        $invitation->setRelation('inviter', User::factory()->make(['name' => 'Ada Lovelace']));

        $mail = new OrganizationInvitationMail(
            $invitation,
            'https://michicast.test/invitation-acceptances/invitation-token/edit',
        );

        $mail->assertSeeInHtml('Michimono Labs');
        $mail->assertSeeInHtml('Editor');
        $mail->assertSeeInHtml('Ada Lovelace');
        $mail->assertSeeInHtml('https://michicast.test/invitation-acceptances/invitation-token/edit');
    }

    public function test_a_dangerous_organization_name_is_escaped_on_the_acceptance_screen_and_in_mail(): void
    {
        $name = '<script>alert("xss")</script>';
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $organization->update(['name' => $name]);

        OrganizationInvitation::factory()
            ->for($organization)
            ->for($owner, 'inviter')
            ->forEmail('ada@example.test')
            ->role(Role::Viewer)
            ->withPlainToken('invitation-token')
            ->create();

        $this->get(route('invitation-acceptances.edit', 'invitation-token'))
            ->assertOk()
            ->assertDontSee($name, false)
            ->assertSee($name);

        $invitation = OrganizationInvitation::query()->sole()->load(['organization', 'inviter']);
        $html = (new OrganizationInvitationMail($invitation, 'https://example.test/accept'))->render();

        $this->assertStringNotContainsString($name, $html);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdministrativeRoles(): array
    {
        return [
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    public function test_invitations_are_rate_limited_per_member(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->memberHolding(Role::Owner);
        // Rate-limit coverage needs headroom past the free-plan members cap (#162).
        $organization->update(['plan' => 'unlimited']);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->actingAs($owner)
                ->post(route('invitations.store', $organization), [
                    'email' => "guest{$attempt}@example.test",
                    'role' => Role::Editor->value,
                ])
                ->assertRedirect(route('members.index', $organization));
        }

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'one-too-many@example.test',
                'role' => Role::Editor->value,
            ])
            ->assertTooManyRequests()
            ->assertSee('Too many attempts');

        $this->assertDatabaseMissing('organization_invitations', ['email' => 'one-too-many@example.test']);
        Mail::assertQueuedCount(10);
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create(['name' => 'Michimono Labs'])];
    }
}
