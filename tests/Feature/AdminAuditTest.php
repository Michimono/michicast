<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_page_is_404_for_non_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.audit.index'))
            ->assertNotFound();
    }

    public function test_audit_page_lists_entries_for_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test', 'other-admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test', 'name' => 'Ada Admin']);
        $other = User::factory()->create(['email' => 'other-admin@example.test', 'name' => 'Other Admin']);

        AdminActionLog::factory()->forAdmin($admin)->create([
            'action' => AdminAction::SignedIn,
            'ip_address' => '203.0.113.10',
        ]);

        AdminActionLog::factory()->forSubject($other, $admin)->create([
            'action' => AdminAction::SignedIn,
            'reason' => 'checked access',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.audit.index'));

        $response->assertOk()
            ->assertSee('Audit', false)
            ->assertSee('Signed in', false)
            ->assertSee('Ada Admin', false)
            ->assertSee('Other Admin', false)
            ->assertSee('203.0.113.10', false)
            ->assertSee('checked access', false);
    }

    public function test_audit_page_filters_by_admin_action_and_subject(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test', 'other@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $subject = User::factory()->create(['email' => 'subject-user@example.test']);

        AdminActionLog::factory()->forAdmin($admin)->create([
            'reason' => 'first-entry-reason',
        ]);
        AdminActionLog::factory()->forSubject($other, $subject)->create([
            'reason' => 'filtered-entry-reason',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.audit.index', [
                'admin_id' => $other->id,
                'action' => AdminAction::SignedIn->value,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->id,
            ]))
            ->assertOk()
            ->assertSee('filtered-entry-reason', false)
            ->assertSee('subject-user@example.test', false)
            ->assertDontSee('first-entry-reason', false);
    }

    public function test_admin_sign_in_is_recorded_after_password_confirmation(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->assertDatabaseCount('admin_actions', 0);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->get(route('admin.overview'))
            ->assertOk();

        $this->assertDatabaseCount('admin_actions', 1);

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::SignedIn->value,
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->id,
            'ip_address' => '198.51.100.20',
        ]);

        // Same password confirmation window does not write a second row.
        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk();

        $this->assertDatabaseCount('admin_actions', 1);
    }

    public function test_reconfirming_password_records_another_sign_in(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $first = time() - 100;

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => $first])
            ->get(route('admin.overview'))
            ->assertOk();

        $this->assertDatabaseCount('admin_actions', 1);

        $second = time();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => $second])
            ->get(route('admin.overview'))
            ->assertOk();

        $this->assertDatabaseCount('admin_actions', 2);
    }

    public function test_admin_actions_are_append_only(): void
    {
        $admin = User::factory()->create();
        $entry = AdminActionLog::factory()->forAdmin($admin)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $entry->update(['reason' => 'tamper']);
    }
}
