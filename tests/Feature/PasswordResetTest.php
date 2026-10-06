<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reset_link_screen_can_be_rendered(): void
    {
        $this->get(route('password-reset-links.create'))->assertOk();
    }

    public function test_a_user_can_request_a_reset_link_pointing_at_the_reset_screen(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password-reset-links.store'), ['email' => $user->email])
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $this->assertSame(
                route('passwords.edit', ['token' => $notification->token, 'email' => $user->email]),
                $notification->toMail($user)->actionUrl,
            );

            return true;
        });
    }

    public function test_password_reset_notification_is_queued_on_the_mail_queue(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password-reset-links.store'), ['email' => $user->email])
            ->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification): bool {
            return $notification->queue === 'mail';
        });
    }

    public function test_no_reset_link_is_sent_for_an_unknown_address(): void
    {
        Notification::fake();

        $this->post(route('password-reset-links.store'), ['email' => 'nobody@example.test'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_a_user_can_set_a_new_password_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get(route('passwords.edit', ['token' => $token, 'email' => $user->email]))->assertOk();

        $response = $this->put(route('passwords.update', $token), [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $response->assertRedirect(route('session.create'));
        $this->assertTrue(Hash::check('a-brand-new-password', $user->refresh()->password));
    }

    public function test_a_reset_is_rejected_with_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->put(route('passwords.update', 'not-a-real-token'), [
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_repeated_reset_link_requests_are_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password-reset-links.store'), ['email' => $user->email])->assertRedirect();
        }

        $this->post(route('password-reset-links.store'), ['email' => $user->email])->assertTooManyRequests();
    }
}
