<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $organization = Organization::factory()->create();

        $this->get(route('organizations.members.index', $organization))
            ->assertRedirect(route('session.create'));
    }

    public function test_the_members_screen_lists_every_member_with_their_role(): void
    {
        $owner = User::factory()->create(['name' => 'Ada Lovelace']);
        $viewer = User::factory()->create(['name' => 'Grace Hopper']);

        $organization = Organization::factory()->ownedBy($owner)->create();
        $organization->members()->create(['user_id' => $viewer->getKey(), 'role' => Role::Viewer]);

        $this->actingAs($owner)
            ->get(route('organizations.members.index', $organization))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Owner')
            ->assertSee('Grace Hopper')
            ->assertSee('Viewer');
    }

    public function test_someone_who_does_not_belong_cannot_see_the_members(): void
    {
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('organizations.members.index', $organization))
            ->assertForbidden();
    }

    public function test_an_owner_can_change_a_role(): void
    {
        [$organization, $owner] = $this->organizationOwnedBySomeone();
        $member = $this->member($organization, Role::Viewer);

        $response = $this->actingAs($owner)->patch(route('members.role.update', $member), [
            'role' => Role::Editor->value,
        ]);

        $response->assertRedirect(route('organizations.members.index', $organization));
        $response->assertSessionHas('success');

        $this->assertSame(Role::Editor, $member->refresh()->role);
    }

    public function test_an_admin_can_change_a_role(): void
    {
        [$organization] = $this->organizationOwnedBySomeone();
        $admin = $this->member($organization, Role::Admin)->user;
        $member = $this->member($organization, Role::Viewer);

        $this->actingAs($admin)
            ->patch(route('members.role.update', $member), ['role' => Role::Editor->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Editor, $member->refresh()->role);
    }

    public function test_an_editor_cannot_change_a_role(): void
    {
        [$organization] = $this->organizationOwnedBySomeone();
        $editor = $this->member($organization, Role::Editor)->user;
        $member = $this->member($organization, Role::Viewer);

        $this->actingAs($editor)
            ->patch(route('members.role.update', $member), ['role' => Role::Admin->value])
            ->assertForbidden();

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_a_viewer_cannot_change_a_role(): void
    {
        [$organization] = $this->organizationOwnedBySomeone();
        $viewer = $this->member($organization, Role::Viewer)->user;
        $member = $this->member($organization, Role::Viewer);

        $this->actingAs($viewer)
            ->patch(route('members.role.update', $member), ['role' => Role::Admin->value])
            ->assertForbidden();

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_an_admin_of_another_organization_cannot_change_a_role_here(): void
    {
        [$organization] = $this->organizationOwnedBySomeone();
        $member = $this->member($organization, Role::Viewer);

        $elsewhere = Organization::factory()->create();
        $outsider = $this->member($elsewhere, Role::Admin)->user;

        $this->actingAs($outsider)
            ->patch(route('members.role.update', $member), ['role' => Role::Admin->value])
            ->assertForbidden();

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        [$organization, $owner] = $this->organizationOwnedBySomeone();
        $ownership = $organization->members()->where('user_id', $owner->getKey())->sole();

        $this->actingAs($owner)
            ->patch(route('members.role.update', $ownership), ['role' => Role::Admin->value])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Owner, $ownership->refresh()->role);
    }

    public function test_an_owner_can_step_down_once_another_owner_exists(): void
    {
        [$organization, $owner] = $this->organizationOwnedBySomeone();
        $ownership = $organization->members()->where('user_id', $owner->getKey())->sole();

        $this->member($organization, Role::Owner);

        $this->actingAs($owner)
            ->patch(route('members.role.update', $ownership), ['role' => Role::Admin->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Admin, $ownership->refresh()->role);
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        [$organization, $owner] = $this->organizationOwnedBySomeone();
        $member = $this->member($organization, Role::Viewer);

        $this->actingAs($owner)
            ->patch(route('members.role.update', $member), ['role' => 'superuser'])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_the_role_screen_is_only_offered_to_members_who_manage_members(): void
    {
        [$organization, $owner] = $this->organizationOwnedBySomeone();
        $member = $this->member($organization, Role::Viewer);

        $this->actingAs($owner)
            ->get(route('organizations.members.index', $organization))
            ->assertOk()
            ->assertSee(route('members.role.edit', $member), false);

        $this->actingAs($member->user)
            ->get(route('organizations.members.index', $organization))
            ->assertOk()
            ->assertDontSee(route('members.role.edit', $member), false);
    }

    public function test_a_user_cannot_hold_two_roles_in_the_same_organization(): void
    {
        [$organization] = $this->organizationOwnedBySomeone();
        $member = $this->member($organization, Role::Viewer);

        $this->expectException(QueryException::class);

        $organization->members()->create([
            'user_id' => $member->user_id,
            'role' => Role::Admin,
        ]);
    }

    /**
     * @return array{Organization, User}
     */
    private function organizationOwnedBySomeone(): array
    {
        $owner = User::factory()->create();

        return [Organization::factory()->ownedBy($owner)->create(), $owner];
    }

    private function member(Organization $organization, Role $role): OrganizationMember
    {
        return OrganizationMember::factory()->for($organization)->role($role)->create();
    }
}
