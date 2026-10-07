<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\Role;
use App\Models\AdminActionLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectRunner;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminOrganizationSuspendTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_skips_projects_in_a_suspended_organization(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $runner = new CountingSuspendProjectRunner;
        $this->app->instance(ProjectRunner::class, $runner);

        $activeOrg = Organization::factory()->create();
        $suspendedOrg = Organization::factory()->suspended()->create();

        $due = Project::factory()->for($activeOrg)->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $blocked = Project::factory()->for($suspendedOrg)->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame(1, $runner->runs);
        $this->assertTrue($due->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
        $this->assertTrue($blocked->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
        $this->assertNull($blocked->claimed_at);
        $this->assertFalse($blocked->isDispatchable());
    }

    public function test_owner_writes_are_refused_while_viewer_reads_still_work(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->suspended('Abuse')->create();
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
            'name' => 'Suspended Broadcast',
        ]);

        $this->actingAs($owner)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('This organization is suspended', false)
            ->assertDontSee('Abuse', false);

        $this->actingAs($owner)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Suspended Broadcast', false);

        $originalName = $organization->name;

        $this->actingAs($owner)
            ->patch(route('organization-settings.update', $organization), [
                'name' => 'Hijacked',
                'timezone' => 'UTC',
            ])
            ->assertForbidden()
            ->assertSee('This organization is suspended', false);

        $this->actingAs($owner)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertForbidden()
            ->assertSee('This organization is suspended', false);

        $this->actingAs($owner)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertForbidden()
            ->assertSee('This organization is suspended', false);

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'new@example.test',
                'role' => Role::Viewer->value,
            ])
            ->assertForbidden()
            ->assertSee('This organization is suspended', false);

        $this->assertSame($originalName, $organization->refresh()->name);
    }

    public function test_platform_admin_banner_shows_suspension_reason(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->ownedBy($admin)->suspended('Policy violation')->create();

        $this->actingAs($admin)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('This organization is suspended', false)
            ->assertSee('Policy violation', false);
    }

    public function test_suspend_and_reinstate_are_audited_and_recalculate_next_run_at(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['name' => 'Acme Radio']);
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-10 09:00:00', 'UTC'),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.suspend.store', $organization), [
                'reason' => 'Terms violation',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $organization->refresh();
        $this->assertTrue($organization->isSuspended());
        $this->assertSame('Terms violation', $organization->suspension_reason);
        $this->assertSame($admin->id, $organization->suspended_by);

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::Suspended->value,
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'reason' => 'Terms violation',
        ]);

        // While suspended, a due next_run_at must not dispatch.
        $this->artisan('projects:dispatch')->assertSuccessful();
        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-10 09:00:00', 'UTC')));

        $this->travelTo(Carbon::parse('2026-04-01 10:00:00', 'UTC'));

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.organizations.suspend.destroy', $organization))
            ->assertRedirect(route('admin.organizations.show', $organization));

        $organization->refresh();
        $this->assertFalse($organization->isSuspended());
        $this->assertNull($organization->suspension_reason);
        $this->assertNull($organization->suspended_by);

        $project->refresh();
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-04-02 09:00:00', 'UTC')));

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::Reinstated->value,
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'reason' => 'Terms violation',
        ]);

        $this->assertSame(2, AdminActionLog::query()->where('subject_id', $organization->id)->count());
    }

    public function test_banning_with_sole_owned_suspend_checkbox_suspends_those_organizations(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $user = User::factory()->create();
        $soleOwned = Organization::factory()->ownedBy($user)->create(['name' => 'Solo Shop']);
        $shared = Organization::factory()->ownedBy($user)->create(['name' => 'Shared Shop']);
        $shared->members()->create([
            'user_id' => User::factory()->create()->id,
            'role' => Role::Owner,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.users.ban.store', $user), [
                'reason' => 'Spam',
                'confirmation' => '1',
                'suspend_sole_owned_organizations' => '1',
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertTrue($soleOwned->refresh()->isSuspended());
        $this->assertStringContainsString('Sole owner banned', (string) $soleOwned->suspension_reason);
        $this->assertFalse($shared->refresh()->isSuspended());

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::Suspended->value,
            'subject_type' => $soleOwned->getMorphClass(),
            'subject_id' => $soleOwned->id,
        ]);
    }
}

class CountingSuspendProjectRunner implements ProjectRunner
{
    public int $runs = 0;

    public function run(Project $project, ?CarbonInterface $deadline = null): bool
    {
        $this->runs++;

        return true;
    }
}
