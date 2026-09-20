<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('session.create'));
    }

    public function test_the_settings_screen_has_a_section_rail_and_a_card_per_section(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('id="settings-sidebar"', false)
            ->assertSee('aria-label="Settings sections"', false)
            ->assertSee('href="#profile"', false)
            ->assertSee('href="#password"', false)
            ->assertSee('id="profile"', false)
            ->assertSee('id="password"', false)
            ->assertSee('>Profile</h2>', false)
            ->assertSee('>Password</h2>', false)
            ->assertSee('Settings menu')
            ->assertSee('Current password')
            ->assertDontSee('id="organization-sidebar"', false)
            ->assertDontSee('<footer', false)
            ->assertSee('>Settings</a>', false);
    }

    public function test_the_profile_screen_shows_the_current_values_and_says_the_timezone_is_display_only(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'timezone' => 'Europe/Madrid',
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee($user->email)
            ->assertSee('<option value="Europe/Madrid" selected>', false)
            ->assertSee('Only changes how times are shown to you. Schedules keep running on their own timezone.');
    }

    public function test_a_user_can_update_their_name_email_and_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'timezone' => 'Asia/Tokyo',
        ]);

        $response->assertRedirect(route('profile.edit'));
        $response->assertSessionHas('success');

        $user->refresh();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada@example.test', $user->email);
        $this->assertSame('Asia/Tokyo', $user->timezone);
    }

    public function test_a_user_can_save_the_profile_while_keeping_their_own_email(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Ada King',
                'email' => $user->email,
                'timezone' => 'UTC',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ada King', $user->refresh()->name);
    }

    public function test_an_email_already_taken_by_another_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Ada Lovelace',
            'email' => $other->email,
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNotSame($other->email, $user->refresh()->email);
    }

    public function test_an_unknown_timezone_identifier_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Mars/Olympus_Mons',
        ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertSame('UTC', $user->refresh()->timezone);
    }

    public function test_a_fixed_utc_offset_is_rejected_so_only_identifiers_are_stored(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => '+02:00',
        ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertSame('UTC', $user->refresh()->timezone);
    }
}
