<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DropOrgCardCountsAndArchiveChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_cards_keep_antenna_and_ears_without_counts(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Quiet Relay']);
        Source::factory()->for($project)->create();
        Destination::factory()->for($project)->create();

        $html = $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Quiet Relay')
            ->assertSee('>Broadcast</span>', false)
            ->assertDontSee('>Sources</dt>', false)
            ->assertDontSee('>Destinations</dt>', false)
            ->assertDontSee('>Broadcasts</dt>', false)
            ->getContent();

        $this->assertStringContainsString('viewBox="0 0 64 10"', $html);
        $this->assertStringContainsString('M12 21v-8', $html);
    }

    public function test_contextual_menu_omits_sources_and_destinations(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Overview</span>', $html);
        $this->assertStringContainsString('>Activity</span>', $html);
        $this->assertStringNotContainsString('>Sources</span>', $html);
        $this->assertStringNotContainsString('>Destinations</span>', $html);
        $this->assertStringNotContainsString('>Add a source</', $html);
        $this->assertStringNotContainsString('>Add destination</', $html);
    }

    public function test_deleting_source_then_destination_allows_readding_on_overview(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['name' => 'Old feed']);
        $destination = Destination::factory()->for($project)->create(['name' => 'Old channel']);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('sources.destroy', [$organization, $source]), ['confirmation' => '1'])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Add a source')
            ->assertSee('Old channel');

        $this->actingAs($user)
            ->delete(route('destinations.destroy', [$organization, $destination]), ['confirmation' => '1'])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Add a source')
            ->assertSee('Add destination')
            ->assertDontSee('Old feed')
            ->assertDontSee('Old channel');
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
