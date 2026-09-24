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

class SingleBroadcastPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_source_and_destination_creates_the_default_broadcast(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create(['name' => 'Relay']);

        $source = Source::factory()->for($project)->create(['name' => 'Feed']);
        $project->ensureDefaultBroadcast();
        $this->assertNull($project->primaryBroadcast());

        Destination::factory()->for($project)->create(['name' => 'Channel']);
        $broadcast = $project->ensureDefaultBroadcast();

        $this->assertNotNull($broadcast);
        $this->assertSame('Relay', $broadcast->name);
        $this->assertTrue($broadcast->source->is($source));
        $this->assertSame(1, $project->broadcasts()->notArchived()->count());
    }

    public function test_a_second_broadcast_cannot_be_created_when_a_pipeline_exists(): void
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
        $extraSource = Source::factory()->for($project)->create();
        $extraDestination = Destination::factory()->for($project)->create();

        $this->actingAs($user)
            ->get(route('project-broadcasts.create', [$organization, $project]))
            ->assertRedirect(route('projects.show', [$organization, $project]))
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->post(route('project-broadcasts.store', [$organization, $project]), [
                'name' => 'Second route',
                'source_id' => $extraSource->getKey(),
                'destination_id' => $extraDestination->getKey(),
            ])
            ->assertRedirect(route('projects.show', [$organization, $project]))
            ->assertSessionHas('error');

        $this->assertSame(1, $project->broadcasts()->notArchived()->count());
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
