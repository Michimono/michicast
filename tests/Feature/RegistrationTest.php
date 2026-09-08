<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_can_be_rendered(): void
    {
        $this->get(route('users.create'))->assertOk();
    }

    public function test_a_visitor_can_register_and_lands_authenticated(): void
    {
        $response = $this->post(route('users.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::whereEmail('ada@example.test')->sole();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('UTC', $user->timezone);
        $this->assertTrue(Hash::check('analytical-engine', $user->password));
    }

    public function test_registration_is_rejected_when_the_email_is_already_taken(): void
    {
        $existing = User::factory()->create();

        $response = $this->post(route('users.store'), [
            'name' => 'Ada Lovelace',
            'email' => $existing->email,
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_registration_is_rejected_when_the_password_is_not_confirmed(): void
    {
        $response = $this->post(route('users.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'analytical-engine',
            'password_confirmation' => 'difference-engine',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }
}
