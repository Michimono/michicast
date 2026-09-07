<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_out(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->delete(route('session.destroy'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_a_guest_cannot_log_out(): void
    {
        $this->delete(route('session.destroy'))->assertRedirect(route('session.create'));
    }
}
