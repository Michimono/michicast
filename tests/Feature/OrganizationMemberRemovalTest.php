<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationMemberRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_remove_a_member(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $this->someoneElseIn($organization, Role::Viewer);

        $this->delete(route('members.destroy', $member))
            ->assertRedirect(route('session.create'));

        $this->assertSame(Role::Viewer, $organization->fresh()->roleFor($member->user));
    }

    public function test_an_owner_can_remove_a_member(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $this->someoneElseIn($organization, Role::Viewer);

        $this->actingAs($owner)
            ->delete(route('members.destroy', $member))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('success');

        $this->assertNull($organization->fresh()->roleFor($member->user));
    }

    public function test_a_member_can_leave_voluntarily(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $this->someoneElseIn($organization, Role::Editor);

        $this->actingAs($member->user)
            ->delete(route('members.destroy', $member))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $this->assertNull($organization->fresh()->roleFor($member->user));
        $this->assertNull(session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_removing_a_member_revokes_access_on_the_next_request(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $this->someoneElseIn($organization, Role::Viewer);

        $this->actingAs($owner)->delete(route('members.destroy', $member));

        $this->actingAs($member->user)
            ->get(route('members.index'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_the_last_owner_cannot_be_removed_or_leave(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $ownership = $organization->members()->where('user_id', $owner->getKey())->sole();

        $this->actingAs($owner)
            ->delete(route('members.destroy', $ownership))
            ->assertRedirect(route('members.index'))
            ->assertSessionHas('error');

        $this->assertSame(Role::Owner, $organization->fresh()->roleFor($owner));
    }

    public function test_an_owner_can_leave_once_another_owner_exists(): void
    {
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $this->someoneElseIn($organization, Role::Owner);
        $ownership = $organization->members()->where('user_id', $owner->getKey())->sole();

        $this->actingAs($owner)
            ->delete(route('members.destroy', $ownership))
            ->assertRedirect(route('dashboard'));

        $this->assertNull($organization->fresh()->roleFor($owner));
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_a_non_administrator_cannot_remove_someone_else(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role);
        $member = $this->someoneElseIn($organization, Role::Viewer);

        $this->actingAs($user)
            ->delete(route('members.destroy', $member))
            ->assertForbidden();

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_a_member_of_another_organization_cannot_remove_someone_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $member = $this->someoneElseIn($organization, Role::Viewer);

        $this->actingAs($outsider)
            ->delete(route('members.destroy', $member))
            ->assertNotFound();

        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdministrativeRoles(): array
    {
        return [
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }

    private function someoneElseIn(Organization $organization, Role $role): OrganizationMember
    {
        return OrganizationMember::factory()->for($organization)->role($role)->create();
    }
}
