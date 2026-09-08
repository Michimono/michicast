<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfilePasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('profile.password.edit'))->assertRedirect(route('session.create'));
    }

    public function test_the_password_screen_can_be_rendered(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.password.edit'))
            ->assertOk()
            ->assertSee('Current password');
    }

    public function test_a_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ]);

        $response->assertRedirect(route('profile.password.edit'));
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('analytical-engine', $user->refresh()->password));
    }

    public function test_the_change_is_rejected_when_the_current_password_is_wrong(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('profile.password.update'), [
            'current_password' => 'difference-engine',
            'password' => 'analytical-engine',
            'password_confirmation' => 'analytical-engine',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_change_is_rejected_when_the_new_password_is_not_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'analytical-engine',
            'password_confirmation' => 'difference-engine',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }
}
