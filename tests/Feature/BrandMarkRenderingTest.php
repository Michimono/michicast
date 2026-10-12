<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gradient cat mark (x-brand.mark variant="cat" gradient, used by
 * x-empty-state) must render as an element, never as escaped text, and its
 * mask must resolve in both the Vite dev server and the built stylesheet.
 */
class BrandMarkRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_reached_around_the_first_organization_render_the_mark_as_markup(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('organizations.index'))
            ->assertRedirect(route('organizations.create'));

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertDontSee('&lt;span role=&quot;img&quot;', false);

        $organization = Organization::factory()->ownedBy($user)->create();

        foreach ([route('organizations.index'), route('organizations.show', $organization)] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertDontSee('&lt;span role=&quot;img&quot;', false);
        }

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertSee('class="house-mark block house-mark-cat aspect-[47/52] h-16"', false);
    }

    public function test_guest_pages_render_the_mark_as_markup(): void
    {
        foreach ([route('session.create'), route('home')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('&lt;span role=&quot;img&quot;', false)
                ->assertDontSee('&lt;img src=', false);
        }
    }

    public function test_brand_masks_use_paths_vite_resolves_in_dev_and_build(): void
    {
        // A root-relative url('/images/...') resolves against the Vite dev
        // server origin (which does not serve public/), so the mask fails and
        // the gradient shows as a solid block. Relative paths are rewritten
        // by Vite in both modes.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString("url('/images/brand/", $css);
        $this->assertStringContainsString("url('../../public/images/brand/logo-day-full.svg')", $css);
    }
}
