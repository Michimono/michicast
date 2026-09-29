<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationArchivedBroadcastsTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_sidebar_includes_archived_link(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);

        $html = $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Archived</span>', $html);
        $this->assertStringContainsString(route('archived-broadcasts.index', $organization), $html);
    }

    public function test_archived_page_lists_archived_broadcasts_only(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        Project::factory()->for($organization)->create(['name' => 'Live Relay']);
        Project::factory()->for($organization)->archived()->create(['name' => 'Shelved Digest']);

        $html = $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $organization))
            ->assertOk()
            ->assertSee('Shelved Digest')
            ->assertDontSee('Live Relay')
            ->getContent();

        $this->assertAnchorIsCurrent(route('archived-broadcasts.index', $organization), $html);
        $this->assertStringContainsString('>Archived</span>', $html);
        $this->assertStringNotContainsString('aria-label="Broadcast"', $html);
    }

    public function test_active_org_workspace_still_hides_archived_broadcasts(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        Project::factory()->for($organization)->create(['name' => 'Live Relay']);
        Project::factory()->for($organization)->archived()->create(['name' => 'Shelved Digest']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Live Relay')
            ->assertDontSee('Shelved Digest');
    }

    public function test_archived_list_is_isolated_across_organizations(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $other = Organization::factory()->ownedBy($user)->create();

        Project::factory()->for($organization)->archived()->create(['name' => 'Ours Archived']);
        Project::factory()->for($other)->archived()->create(['name' => 'Theirs Archived']);

        $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $organization))
            ->assertOk()
            ->assertSee('Ours Archived')
            ->assertDontSee('Theirs Archived');

        $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $other))
            ->assertOk()
            ->assertSee('Theirs Archived')
            ->assertDontSee('Ours Archived');
    }

    public function test_an_archived_broadcast_can_be_opened_and_restored_from_the_list(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->archived()->create(['name' => 'Shelved Digest']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Shelved Digest')
            ->assertDontSee('>Restore</button>', false);

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Restore');

        $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $organization))
            ->assertOk()
            ->assertSee('Restore')
            ->assertSee(route('broadcasts.show', [$organization, $project]), false);

        $this->actingAs($user)
            ->delete(route('archived-broadcasts.destroy', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));

        $this->assertFalse($project->refresh()->canRestore());

        $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $organization))
            ->assertOk()
            ->assertDontSee('Shelved Digest');
    }

    public function test_a_non_member_cannot_open_another_organizations_archived_list(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create();
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($user)
            ->get(route('archived-broadcasts.index', $foreign))
            ->assertNotFound();
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
