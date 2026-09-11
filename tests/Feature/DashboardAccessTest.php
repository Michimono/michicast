<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('session.create'));
    }

    public function test_an_authenticated_user_is_sent_to_their_organization(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', $organization));
    }
}
