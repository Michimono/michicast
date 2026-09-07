<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimestampDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-01-02 23:30:00', 'UTC'));
    }

    public function test_timestamps_render_in_the_timezone_the_user_chose(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jan 3, 2026 8:30 AM JST');
    }

    public function test_the_same_moment_renders_differently_for_a_user_reading_times_in_utc(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jan 2, 2026 11:30 PM UTC')
            ->assertSee('datetime="2026-01-02T23:30:00+00:00"', false);
    }

    public function test_timestamps_fall_back_to_the_application_timezone_without_a_signed_in_user(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);

        $this->blade('<x-timestamp :value="$value" />', ['value' => Carbon::parse('2026-01-02 23:30:00', 'UTC')])
            ->assertSee('Jan 3, 2026 8:30 AM JST');
    }
}
