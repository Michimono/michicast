<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RssSourceProvider;
use App\Support\SourceProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_renders_the_public_site_shell(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<header', false);
        $response->assertSee('aria-label="Site"', false);
        $response->assertSee('<main id="content"', false);
        $response->assertSee('aria-label="Footer"', false);
    }

    public function test_the_public_shell_shows_a_flash_message_from_the_session(): void
    {
        $response = $this->withSession(['success' => 'Everything worked.'])->get(route('home'));

        $response->assertOk();
        $response->assertSee('Everything worked.');
    }

    public function test_the_home_page_describes_the_product_to_visitors_and_search_engines(): void
    {
        config(['app.name' => 'Configured Product']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Draw the <em>route</em> your information takes.', false)
            ->assertSee('<meta name="description" content="Configured Product is the visual platform for routing information.', false);
    }

    public function test_the_home_page_presents_a_commercial_product(): void
    {
        config(['michicast.brand' => 'Configured Brand']);

        $this->travelTo('2031-05-04');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('2031 Configured Brand. All rights reserved.')
            ->assertDontSee('MIT license')
            ->assertDontSee('pen-source')
            ->assertDontSee('pen source')
            ->assertDontSee('github.com')
            ->assertDontSee('Docker');
    }

    public function test_the_section_links_point_at_sections_that_exist(): void
    {
        $response = $this->get(route('home'))->assertOk();

        foreach (['how-it-works', 'capabilities', 'use-cases', 'teams'] as $section) {
            $response->assertSee('href="#'.$section.'"', false);
            $response->assertSee('id="'.$section.'"', false);
        }
    }

    public function test_a_guest_is_invited_to_get_started_while_registration_is_open(): void
    {
        config(['michicast.registration.mode' => 'open']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Get started')
            ->assertSee('href="'.route('users.create').'"', false)
            ->assertSee('I already have an account')
            ->assertSee('href="'.route('session.create').'"', false)
            ->assertDontSee('href="'.route('dashboard').'"', false);
    }

    public function test_a_guest_is_only_offered_log_in_while_registration_is_closed(): void
    {
        config(['michicast.registration.mode' => 'closed']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Get started')
            ->assertDontSee('I already have an account')
            ->assertDontSee('href="'.route('users.create').'"', false)
            ->assertSee('href="'.route('session.create').'"', false);
    }

    public function test_a_signed_in_user_is_pointed_at_the_application_instead_of_registration(): void
    {
        config([
            'app.name' => 'Configured Product',
            'michicast.registration.mode' => 'open',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Open Configured Product')
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('Get started')
            ->assertDontSee('href="'.route('users.create').'"', false)
            ->assertDontSee('href="'.route('session.create').'"', false);
    }

    public function test_the_public_shell_leaves_out_the_application_account_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('aria-label="Account"', false)
            ->assertDontSee('href="'.route('profile.edit').'"', false)
            ->assertDontSee('href="'.route('organizations.index').'"', false)
            ->assertDontSee('Log out');
    }

    public function test_the_page_names_only_the_registered_live_providers(): void
    {
        config([
            'michicast.sources' => ['rss' => RssSourceProvider::class],
            'michicast.test_destinations_enabled' => true,
        ]);
        $this->app->forgetInstance(SourceProviderRegistry::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('RSS / Atom')
            ->assertSee('Telegram')
            ->assertDontSee('Reddit')
            ->assertDontSee('r/netsec')
            ->assertDontSee('Community radar')
            ->assertDontSee('Web page')
            ->assertDontSee('Activity log');
    }

    public function test_the_page_still_renders_when_no_source_provider_is_registered(): void
    {
        config(['michicast.sources' => []]);
        $this->app->forgetInstance(SourceProviderRegistry::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Draw the <em>route</em> your information takes.', false)
            ->assertDontSee('aria-label="Source types"', false)
            ->assertDontSee('href="#use-cases"', false)
            ->assertDontSee('id="use-cases"', false);
    }

    public function test_the_contact_links_follow_the_configured_support_address(): void
    {
        config(['michicast.support_email' => 'sales@example.test']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Talk to our team')
            ->assertSee('href="mailto:sales@example.test"', false);
    }

    public function test_the_contact_links_are_hidden_when_no_support_address_is_configured(): void
    {
        config(['michicast.support_email' => '']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Talk to our team')
            ->assertDontSee('mailto:', false);
    }

    public function test_the_documentation_links_follow_the_configured_address(): void
    {
        config(['michicast.documentation_url' => 'https://example.test/handbook']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Docs')
            ->assertSee('Documentation')
            ->assertSee('href="https://example.test/handbook"', false);
    }

    public function test_the_documentation_links_are_hidden_until_an_address_is_configured(): void
    {
        config(['michicast.documentation_url' => null]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Docs')
            ->assertDontSee('Documentation');
    }
}
