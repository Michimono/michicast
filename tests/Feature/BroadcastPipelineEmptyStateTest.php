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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastPipelineEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function source_mapping_and_destination_each_show_an_empty_state_when_unset(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Quiet start']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('data-pipeline-empty="source"', false)
            ->assertSee('data-pipeline-empty="mapping"', false)
            ->assertSee('data-pipeline-empty="destination"', false)
            ->assertSee('No source yet')
            ->assertSee('No mapping yet')
            ->assertSee('No destination yet')
            ->assertSee('Add a source')
            ->assertSee('Add destination')
            ->assertSee('Connect the source fields to the destination fields.')
            ->assertSee(route('broadcast-sources.create', [$organization, $project]), false)
            ->assertSee(route('broadcast-destinations.create', [$organization, $project]), false)
            ->assertDontSee('Add step');
    }

    #[Test]
    public function source_empty_state_clears_once_a_source_exists(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create(['name' => 'Pocket feed']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('No source yet')
            ->assertDontSee('data-pipeline-empty="source"', false)
            ->assertSee('Pocket feed')
            ->assertSee('Manage')
            ->assertSee('No mapping yet')
            ->assertSee('No destination yet')
            ->assertSee('Add destination')
            ->assertDontSee('Add a source')
            ->assertDontSee('Add step');
    }

    #[Test]
    public function destination_empty_state_clears_once_a_destination_exists(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Destination::factory()->for($project)->create(['name' => 'Telegram room']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('No source yet')
            ->assertSee('Add a source')
            ->assertSee('No mapping yet')
            ->assertDontSee('No destination yet')
            ->assertDontSee('data-pipeline-empty="destination"', false)
            ->assertSee('Telegram room')
            ->assertDontSee('Add destination')
            ->assertDontSee('Add step');
    }

    #[Test]
    public function mapping_shows_add_step_when_both_ends_exist_and_defaults_apply(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake', 'name' => 'Feed']);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording', 'name' => 'Recorder']);
        $broadcast = $project->ensureDefaultBroadcast();
        $this->assertNotNull($broadcast);
        $broadcast->update([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('No source yet')
            ->assertDontSee('No mapping yet')
            ->assertDontSee('No destination yet')
            ->assertSee('Add step')
            ->assertSee('Build the destination data step by step.')
            ->assertSee('Feed')
            ->assertSee('Recorder');
    }

    #[Test]
    public function mapping_empty_state_appears_when_both_ends_exist_but_no_steps_apply(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake', 'name' => 'Feed']);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording', 'name' => 'Recorder']);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'mapping' => ['version' => 1, 'steps' => []],
        ]);

        $response = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('No mapping yet')
            ->assertSee('Add step')
            ->assertSee('data-mapping-empty', false)
            ->assertSee('No steps yet')
            ->assertSee('Connect the source fields to the destination fields.');

        $this->assertNotNull($broadcast->fresh());
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
