<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_can_be_rendered(): void
    {
        $this->get(route('session.create'))->assertOk();
    }

    public function test_a_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_is_rejected_with_a_validation_error_on_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('session.store'), [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('session.create'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_repeated_failed_logins_are_throttled(): void
    {
        $user = User::factory()->create();

        $credentials = [
            'email' => $user->email,
            'password' => 'not-the-password',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('session.store'), $credentials)->assertRedirect();
        }

        $this->post(route('session.store'), $credentials)->assertTooManyRequests();
        $this->assertGuest();
    }
}
