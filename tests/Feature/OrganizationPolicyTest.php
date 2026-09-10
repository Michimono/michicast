<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('everyRole')]
    public function test_every_role_may_view_the_organization_it_belongs_to(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->assertTrue($user->can('view', $organization));
    }

    #[DataProvider('everyRoleAndWhetherItAdministers')]
    public function test_only_admins_and_owners_may_update_the_organization(Role $role, bool $allowed): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->assertSame($allowed, $user->can('update', $organization));
    }

    #[DataProvider('everyRole')]
    public function test_every_role_may_view_a_member_of_its_organization(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->assertTrue($user->can('view', $this->someoneElseIn($organization)));
    }

    #[DataProvider('everyRoleAndWhetherItAdministers')]
    public function test_only_admins_and_owners_may_change_a_members_role(Role $role, bool $allowed): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->assertSame($allowed, $user->can('update', $this->someoneElseIn($organization)));
    }

    public function test_a_user_who_belongs_to_no_organization_is_denied_everything(): void
    {
        $stranger = User::factory()->create();
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->assertFalse($stranger->can('view', $organization));
        $this->assertFalse($stranger->can('update', $organization));
        $this->assertFalse($stranger->can('view', $this->someoneElseIn($organization)));
        $this->assertFalse($stranger->can('update', $this->someoneElseIn($organization)));
    }

    public function test_an_owner_of_another_organization_is_a_stranger_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);

        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->assertFalse($outsider->can('view', $organization));
        $this->assertFalse($outsider->can('update', $organization));
        $this->assertFalse($outsider->can('view', $this->someoneElseIn($organization)));
        $this->assertFalse($outsider->can('update', $this->someoneElseIn($organization)));
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function everyRole(): array
    {
        return [
            'owner' => [Role::Owner],
            'admin' => [Role::Admin],
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    /**
     * @return array<string, array{Role, bool}>
     */
    public static function everyRoleAndWhetherItAdministers(): array
    {
        return [
            'owner' => [Role::Owner, true],
            'admin' => [Role::Admin, true],
            'editor' => [Role::Editor, false],
            'viewer' => [Role::Viewer, false],
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

    private function someoneElseIn(Organization $organization): OrganizationMember
    {
        return OrganizationMember::factory()->for($organization)->role(Role::Viewer)->create();
    }
}
