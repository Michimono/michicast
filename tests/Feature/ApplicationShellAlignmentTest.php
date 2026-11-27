<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationShellAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_header_sets_a_kicker_rule_and_a_display_heading(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-page-header kicker="Workspace" heading="Michimono Labs" lead="Your broadcasts at a glance." />
            BLADE);

        $view->assertSeeInOrder(['Workspace', 'Michimono Labs', 'Your broadcasts at a glance.'], false);
        $view->assertSee('house-fill h-px w-8', false);
        $view->assertSee('<h1', false);
        $view->assertDontSee('h-0.5 min-w-0 flex-1', false);
    }

    public function test_a_page_header_can_carry_a_back_link_and_actions(): void
    {
        $view = $this->blade(<<<'BLADE'
            <x-page-header
                back="/broadcasts"
                back-label="Back to broadcasts"
                kicker="New broadcast"
                heading="Create a broadcast"
            >
                <x-slot:actions>
                    <button type="button">Save</button>
                </x-slot:actions>
            </x-page-header>
            BLADE);

        $view->assertSeeInOrder(['Back to broadcasts', 'New broadcast', 'Create a broadcast', 'Save'], false);
        $view->assertSee('href="/broadcasts"', false);
    }

    public function test_a_card_kicker_uses_the_sites_short_house_rule(): void
    {
        $view = $this->blade('<x-card kicker="Overview" index="01" heading="Broadcast">Body</x-card>');

        $view->assertSeeInOrder(['01', 'Overview', 'Broadcast', 'Body'], false);
        $view->assertSee('house-fill h-px w-8', false);
        $view->assertSee('shadow-float', false);
        $view->assertDontSee('h-0.5 min-w-0 flex-1', false);
    }

    public function test_the_organization_home_borrows_the_sites_header_and_page_language(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        Project::factory()->for($organization)->create(['name' => 'Pokemon TCGP News']);

        $response = $this->actingAs($user)
            ->get(route('organizations.show', $organization));

        $response->assertOk();
        $response->assertSee('sticky top-0 z-40 bg-paper/90 backdrop-blur-md', false);
        $response->assertSee('canvas-dots', false);
        $response->assertSee('house-fill h-px w-8', false);
        $response->assertSeeInOrder(['Workspace', $organization->name], false);
        $response->assertSee('<h1', false);
        $response->assertSee('Pokemon TCGP News');
        $response->assertSee('shadow-float', false);
        $response->assertDontSee('border-b border-line bg-paper-raised', false);
        $response->assertDontSee('max-w-5xl gap-6 py-10', false);
    }

    public function test_settings_and_a_focused_form_keep_the_same_page_grammar(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSeeInOrder(['Account', 'Settings'], false)
            ->assertSee('house-fill h-px w-8', false)
            ->assertSee('sticky top-0 z-40 bg-paper/90 backdrop-blur-md', false)
            ->assertSee('shadow-float', false);

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertOk()
            ->assertSee('Back to broadcasts')
            ->assertSeeInOrder(['New broadcast', 'Create a broadcast'], false)
            ->assertSee('house-fill h-px w-8', false)
            ->assertSee('shadow-float', false);
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
