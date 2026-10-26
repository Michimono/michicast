<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'michicast.platform_admin_emails' => ['admin@example.test'],
            'michicast.organizations.require_approval' => true,
        ]);
    }

    public function test_a_new_organization_waits_for_approval(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Fresh Newsroom', 'timezone' => 'UTC'])
            ->assertRedirect();

        $organization = Organization::query()->where('name', 'Fresh Newsroom')->firstOrFail();

        $this->assertTrue($organization->isPendingApproval());
        $this->assertTrue($organization->isDisabled());

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Fresh Newsroom is almost ready.', false);
    }

    public function test_a_platform_admin_organization_is_approved_straight_away(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->post(route('organizations.store'), ['name' => 'Admin Desk', 'timezone' => 'UTC']);

        $organization = Organization::query()->where('name', 'Admin Desk')->firstOrFail();

        $this->assertFalse($organization->isPendingApproval());
        $this->assertSame($admin->id, $organization->approved_by);
    }

    public function test_approval_can_be_turned_off(): void
    {
        config(['michicast.organizations.require_approval' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Self Hosted', 'timezone' => 'UTC']);

        $this->assertFalse(
            Organization::query()->where('name', 'Self Hosted')->firstOrFail()->isPendingApproval(),
        );
    }

    public function test_a_pending_organization_refuses_writes_and_is_not_dispatched(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->pendingApproval()->create();
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create();

        $this->actingAs($owner)
            ->patch(route('organization-settings.update', $organization), [
                'name' => 'Renamed',
                'timezone' => 'UTC',
            ])
            ->assertForbidden()
            ->assertSee('This organization is waiting for approval', false);

        $this->assertFalse($project->refresh()->isDispatchable());
        $this->assertFalse(Project::query()->dispatchable()->whereKey($project->getKey())->exists());
    }

    public function test_an_admin_approves_a_pending_organization(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->pendingApproval()->create(['name' => 'Waiting Room']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Waiting Room', false)
            ->assertSee('Pending approval', false);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.show', $organization))
            ->assertOk()
            ->assertSee('Approve organization', false);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.approve.store', $organization))
            ->assertRedirect(route('admin.organizations.show', $organization));

        $organization->refresh();

        $this->assertFalse($organization->isPendingApproval());
        $this->assertSame($admin->id, $organization->approved_by);
        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::Approved->value,
            'subject_id' => $organization->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('organization-settings.update', $organization), [
                'name' => 'Open For Business',
                'timezone' => 'UTC',
            ])
            ->assertRedirect();

        $this->assertSame('Open For Business', $organization->refresh()->name);
    }

    public function test_approving_twice_is_refused(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->post(route('admin.organizations.approve.store', $organization))
            ->assertSessionHas('error', 'This organization is already approved.');
    }

    public function test_non_admins_cannot_approve(): void
    {
        $member = User::factory()->create();
        $organization = Organization::factory()->ownedBy($member)->pendingApproval()->create();

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.approve.store', $organization))
            ->assertNotFound();

        $this->assertTrue($organization->refresh()->isPendingApproval());
    }
}
