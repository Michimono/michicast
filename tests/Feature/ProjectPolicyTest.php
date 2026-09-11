<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('everyRole')]
    public function test_every_role_may_list_and_view_projects_in_its_organization(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role);
        $project = Project::factory()->for($organization)->create();

        $this->assertTrue($user->can('viewAny', [Project::class, $organization]));
        $this->assertTrue($user->can('view', $project));
    }

    #[DataProvider('everyRoleAndWhetherItEdits')]
    public function test_only_editors_and_above_may_create_a_project(Role $role, bool $allowed): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->assertSame($allowed, $user->can('create', [Project::class, $organization]));
    }

    #[DataProvider('everyRoleAndWhetherItEdits')]
    public function test_only_editors_and_above_may_update_a_project(Role $role, bool $allowed): void
    {
        [$user, $organization] = $this->memberHolding($role);
        $project = Project::factory()->for($organization)->create();

        $this->assertSame($allowed, $user->can('update', $project));
    }

    #[DataProvider('everyRoleAndWhetherItEdits')]
    public function test_only_editors_and_above_may_change_a_project_status(Role $role, bool $allowed): void
    {
        [$user, $organization] = $this->memberHolding($role);
        $project = Project::factory()->for($organization)->create();

        $this->assertSame($allowed, $user->can('changeStatus', $project));
    }

    public function test_a_user_who_belongs_to_no_organization_is_denied_everything(): void
    {
        $stranger = User::factory()->create();
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();
        $project = Project::factory()->for($organization)->create();

        $this->assertFalse($stranger->can('viewAny', [Project::class, $organization]));
        $this->assertFalse($stranger->can('view', $project));
        $this->assertFalse($stranger->can('create', [Project::class, $organization]));
        $this->assertFalse($stranger->can('update', $project));
        $this->assertFalse($stranger->can('changeStatus', $project));
    }

    public function test_an_owner_of_another_organization_is_a_stranger_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        $organization = Organization::factory()->ownedBy(User::factory()->create())->create();
        $project = Project::factory()->for($organization)->create();

        $this->assertFalse($outsider->can('viewAny', [Project::class, $organization]));
        $this->assertFalse($outsider->can('view', $project));
        $this->assertFalse($outsider->can('create', [Project::class, $organization]));
        $this->assertFalse($outsider->can('update', $project));
        $this->assertFalse($outsider->can('changeStatus', $project));
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
    public static function everyRoleAndWhetherItEdits(): array
    {
        return [
            'owner' => [Role::Owner, true],
            'admin' => [Role::Admin, true],
            'editor' => [Role::Editor, true],
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
}
