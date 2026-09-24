<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandComponentTest extends TestCase
{
    public function test_primary_buttons_carry_a_decorative_paw(): void
    {
        $view = $this->blade('<x-button>Run now</x-button>');

        $view->assertSee('Run now');
        $view->assertSee('aria-hidden="true"', false);
    }

    public function test_secondary_buttons_leave_the_paw_off(): void
    {
        $this->blade('<x-button variant="ghost">Preview content</x-button>')
            ->assertSee('Preview content')
            ->assertDontSee('<svg', false);
    }

    public function test_the_paw_can_be_suppressed_on_a_primary_button(): void
    {
        $this->blade('<x-button :paw="false">Save profile</x-button>')
            ->assertSee('Save profile')
            ->assertDontSee('<svg', false);
    }

    public function test_a_card_kicker_renders_its_label_beside_a_gradient_rule(): void
    {
        $view = $this->blade('<x-card kicker="Deliveries" heading="Recent deliveries">Body</x-card>');

        $view->assertSee('Deliveries');
        $view->assertSee('house-fill', false);
    }

    public function test_only_eared_cards_grow_ears(): void
    {
        $this->blade('<x-card heading="Plain" eared>Body</x-card>')
            ->assertSee('viewBox="0 0 64 10"', false)
            ->assertSee('M2 10 10 1 18 10', false);
        $this->blade('<x-card heading="Plain">Body</x-card>')
            ->assertDontSee('viewBox="0 0 64 10"', false)
            ->assertDontSee('<svg', false);
    }

    public function test_only_eared_empty_states_grow_ears(): void
    {
        $this->blade('<x-empty-state eared heading="Quiet" />')
            ->assertSee('viewBox="0 0 64 10"', false)
            ->assertSee('M2 10 10 1 18 10', false);
        $this->blade('<x-empty-state heading="Quiet" />')
            ->assertDontSee('viewBox="0 0 64 10"', false);
    }

    public function test_social_sign_in_renders_as_disabled_buttons_with_one_visible_reason(): void
    {
        $view = $this->blade('<x-social-auth />');

        $view->assertSee('Google');
        $view->assertSee('GitHub');
        $view->assertSee('Google and GitHub sign-in are not available yet.');

        // Providers must not be followable links while there is nothing to follow.
        $view->assertDontSee('<a ', false);
        $this->assertSame(2, preg_match_all('/<button[^>]*disabled/', (string) $view));
    }

    public function test_the_sidebar_context_keeps_the_resource_name_beside_an_information_icon(): void
    {
        $view = $this->blade('<x-sidebar-context href="/organizations/1" label="Michimono Labs" />');

        $view->assertSee('Michimono Labs');
        $view->assertSee('href="/organizations/1"', false);
        $this->assertSame(1, substr_count((string) $view, '<svg'));
    }

    public function test_the_mark_can_be_filled_with_the_house_gradient(): void
    {
        $this->blade('<x-brand.mark variant="cat" gradient class="h-16" />')
            ->assertSee('house-mark', false)
            ->assertSee('role="img"', false)
            ->assertDontSee('<img', false);
    }
}
