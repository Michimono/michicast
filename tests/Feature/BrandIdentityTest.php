<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_carries_the_brand_mark_and_the_brand_favicon(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('/images/brand/logo-day-face.svg', false);
        $response->assertSee('<link rel="icon" href="/images/brand/logo-day-mark.svg" type="image/svg+xml">', false);
    }

    public function test_contextual_screens_keep_the_application_lockup_in_the_navbar(): void
    {
        config(['app.name' => 'Configured Product']);

        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('/images/brand/logo-day-face.svg', false)
            ->assertSee('Configured Product')
            ->assertSee('Michimono Labs');

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('/images/brand/logo-day-face.svg', false)
            ->assertSee('Configured Product')
            ->assertSee('>Settings</a>', false);
    }

    public function test_the_stock_laravel_favicon_is_no_longer_shipped(): void
    {
        $this->assertFileDoesNotExist(public_path('favicon.ico'));
    }

    public function test_empty_states_show_the_sitting_cat_with_a_playful_line(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Nothing to chase yet');
        $response->assertSee('/images/brand/logo-day-full.svg', false);
    }

    public function test_the_current_page_is_marked_in_the_navigation_for_assistive_technology(): void
    {
        $user = User::factory()->create();

        $organization = Organization::factory()->ownedBy($user)->create();

        $response = $this->actingAs($user)->get(route('organizations.show', $organization));

        $response->assertOk();
        $this->assertAnchorIsCurrent(route('organizations.show', $organization), $response->getContent());
        $this->assertAnchorIsNotCurrent(route('profile.edit'), $response->getContent());
    }

    public function test_the_navigation_marks_settings_while_the_settings_screen_is_open(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk();
        $this->assertAnchorIsCurrent(route('profile.edit'), $response->getContent());
        $this->assertAnchorIsNotCurrent(route('organizations.index'), $response->getContent());
    }

    private function assertAnchorIsNotCurrent(string $url, string $html): void
    {
        $this->assertDoesNotMatchRegularExpression($this->currentAnchorPattern($url), $html);
    }

    private function currentAnchorPattern(string $url): string
    {
        return '/<a[^>]*href="'.preg_quote($url, '/').'"[^>]*aria-current="page"/s';
    }
}
