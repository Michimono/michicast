<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestructiveActionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiving_a_broadcast_without_confirmation_does_nothing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->from(route('broadcasts.edit', [$organization, $project]))
            ->post(route('archived-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHasErrors('confirmation')
            ->assertSessionHas('error', 'Confirm that you want to archive this broadcast.');

        $this->assertSame(ProjectStatus::Active, $project->refresh()->status);
    }

    public function test_archiving_a_flow_without_confirmation_does_nothing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $broadcast = Broadcast::factory()->for(Project::factory()->for($organization))->create();

        $this->actingAs($user)
            ->post(route('archived-flows.store', [$organization, $broadcast]))
            ->assertSessionHasErrors('confirmation');

        $this->assertFalse($broadcast->refresh()->isArchived());
    }

    public function test_removing_a_member_without_confirmation_does_nothing(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $organization->members()->create([
            'user_id' => User::factory()->create()->getKey(),
            'role' => Role::Viewer,
        ]);

        $this->actingAs($owner)
            ->delete(route('members.destroy', [$organization, $member]), ['confirmation' => '0'])
            ->assertSessionHasErrors('confirmation')
            ->assertSessionHas('error', 'Confirm that you want to remove this member.');

        $this->assertSame(Role::Viewer, $organization->fresh()->roleFor($member->user));
    }

    public function test_deleting_a_source_without_confirmation_does_nothing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = Source::factory()->for(Project::factory()->for($organization))->create();

        $this->actingAs($user)
            ->delete(route('sources.destroy', [$organization, $source]))
            ->assertSessionHasErrors('confirmation');

        $this->assertModelExists($source);
    }

    public function test_deleting_a_destination_without_confirmation_does_nothing(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = Destination::factory()->for(Project::factory()->for($organization))->create();

        $this->actingAs($user)
            ->delete(route('destinations.destroy', [$organization, $destination]))
            ->assertSessionHasErrors('confirmation');

        $this->assertModelExists($destination);
    }

    public function test_a_viewer_is_refused_before_confirmation_is_considered(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('archived-broadcasts.store', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_destructive_forms_carry_the_confirmation(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->for($project)->create();
        $organization->members()->create([
            'user_id' => User::factory()->create()->getKey(),
            'role' => Role::Viewer,
        ]);

        foreach ([
            route('broadcasts.edit', [$organization, $project]),
            route('sources.edit', [$organization, $source]),
            route('members.index', $organization),
        ] as $page) {
            $this->actingAs($user)
                ->get($page)
                ->assertOk()
                ->assertSee('name="confirmation" value="1"', false);
        }
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
