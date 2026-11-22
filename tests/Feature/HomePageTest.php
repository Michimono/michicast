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

    public function test_the_home_page_renders_the_layout(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('<header', false);
        $response->assertSee('<nav', false);
        $response->assertSee('<footer', false);
    }

    public function test_the_layout_shows_a_flash_message_from_the_session(): void
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
            ->assertSee('Move information from where it appears to where it is read.')
            ->assertSee('<meta name="description" content="Configured Product collects new items', false);
    }

    public function test_a_guest_is_invited_to_create_an_account_while_registration_is_open(): void
    {
        config(['michicast.registration.mode' => 'open']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Create an account')
            ->assertSee('href="'.route('users.create').'"', false)
            ->assertSee('href="'.route('session.create').'"', false)
            ->assertDontSee('Go to your dashboard');
    }

    public function test_a_guest_is_only_offered_log_in_while_registration_is_closed(): void
    {
        config(['michicast.registration.mode' => 'closed']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Create an account')
            ->assertDontSee('href="'.route('users.create').'"', false)
            ->assertSee('href="'.route('session.create').'"', false);
    }

    public function test_a_signed_in_user_is_pointed_at_the_dashboard_instead_of_registration(): void
    {
        config(['michicast.registration.mode' => 'open']);

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Go to your dashboard')
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('Create an account')
            ->assertDontSee('href="'.route('users.create').'"', false);
    }

    public function test_the_stages_name_only_the_registered_live_providers(): void
    {
        config([
            'michicast.sources' => ['rss' => RssSourceProvider::class],
            'michicast.test_destinations_enabled' => true,
        ]);
        $this->app->forgetInstance(SourceProviderRegistry::class);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('RSS / Atom')
            ->assertDontSee('Reddit')
            ->assertSee('Telegram')
            ->assertDontSee('Activity log');
    }

    public function test_the_source_code_link_follows_the_configured_repository(): void
    {
        config(['michicast.repository_url' => 'https://example.test/forked/repository']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('View the source code')
            ->assertSee('href="https://example.test/forked/repository"', false);
    }

    public function test_the_source_code_link_is_hidden_when_no_repository_is_configured(): void
    {
        config(['michicast.repository_url' => '']);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('View the source code');
    }
}
