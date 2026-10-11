<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\QuotaMetric;
use App\Models\AdminActionLog;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationUsage;
use App\Models\User;
use App\Support\QuotaMeter;
use App\Support\Quotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminOrganizationQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_get_404_on_quota_endpoints(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);

        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($member)
            ->withSession($session)
            ->patch(route('admin.organizations.plan.update', $organization), [
                'plan' => 'unlimited',
                'reason' => 'Should not work',
            ])
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession($session)
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => QuotaMetric::Members->value,
                'limit' => 99,
                'reason' => 'Should not work',
            ])
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession($session)
            ->delete(route('admin.organizations.quota-overrides.destroy', $organization), [
                'metric' => QuotaMetric::Members->value,
                'reason' => 'Should not work',
            ])
            ->assertNotFound();

        $this->actingAs($member)
            ->withSession($session)
            ->post(route('admin.organizations.quota-usage-resets.store', $organization), [
                'metric' => QuotaMetric::SourceFetches->value,
                'reason' => 'Should not work',
            ])
            ->assertNotFound();
    }

    public function test_changing_plan_updates_effective_limits_and_writes_audit(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);
        $quotas = app(Quotas::class);

        $this->assertSame(
            config('michicast.quotas.plans.free.members'),
            $quotas->limitFor($organization, QuotaMetric::Members),
        );

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.organizations.plan.update', $organization), [
                'plan' => 'unlimited',
                'reason' => 'Pilot customer upgrade',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $organization->refresh();
        $this->assertSame('unlimited', $organization->plan);
        $this->assertNull($quotas->limitFor($organization, QuotaMetric::Members));

        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $admin->id,
            'action' => AdminAction::PlanChanged->value,
            'subject_type' => $organization->getMorphClass(),
            'subject_id' => $organization->id,
            'reason' => 'Pilot customer upgrade',
        ]);

        $entry = AdminActionLog::query()->where('action', AdminAction::PlanChanged)->sole();
        $this->assertSame('free', $entry->changes['plan']['before']);
        $this->assertSame('unlimited', $entry->changes['plan']['after']);
    }

    public function test_setting_and_clearing_an_override_changes_effective_limit_and_is_audited(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);
        $quotas = app(Quotas::class);
        $planLimit = (int) config('michicast.quotas.plans.free.members');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => QuotaMetric::Members->value,
                'limit' => 42,
                'note' => 'pilot cohort',
                'reason' => 'Temporary lift for launch',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertSame(42, $quotas->limitFor($organization->fresh(), QuotaMetric::Members));
        $this->assertDatabaseHas('organization_quota_overrides', [
            'organization_id' => $organization->id,
            'metric' => QuotaMetric::Members->value,
            'limit' => 42,
            'note' => 'pilot cohort',
        ]);

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::QuotaOverrideSet->value,
            'reason' => 'Temporary lift for launch',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => QuotaMetric::SourceFetches->value,
                'unlimited' => '1',
                'reason' => 'Uncap fetches for incident recovery',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertNull($quotas->limitFor($organization->fresh(), QuotaMetric::SourceFetches));
        $this->assertDatabaseHas('organization_quota_overrides', [
            'organization_id' => $organization->id,
            'metric' => QuotaMetric::SourceFetches->value,
            'limit' => null,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.organizations.quota-overrides.destroy', $organization), [
                'metric' => QuotaMetric::Members->value,
                'reason' => 'Pilot ended',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertDatabaseMissing('organization_quota_overrides', [
            'organization_id' => $organization->id,
            'metric' => QuotaMetric::Members->value,
        ]);
        $this->assertSame($planLimit, $quotas->limitFor($organization->fresh(), QuotaMetric::Members));

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::QuotaOverrideCleared->value,
            'reason' => 'Pilot ended',
        ]);
    }

    public function test_usage_reset_zeros_current_period_only_and_is_audited(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);
        $this->travelTo(Carbon::parse('2026-10-07 15:00:00', 'UTC'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);
        $meter = app(QuotaMeter::class);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 100,
            'note' => 'test',
        ]);

        $meter->consume($organization, QuotaMetric::SourceFetches, 7);
        $this->assertSame(7, $meter->used($organization, QuotaMetric::SourceFetches));

        // Prior day usage must survive a reset of "today".
        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => '2026-10-06',
            'used' => 55,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.quota-usage-resets.store', $organization), [
                'metric' => QuotaMetric::SourceFetches->value,
                'reason' => 'False alarm metering',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertSame(0, $meter->used($organization, QuotaMetric::SourceFetches));
        $this->assertSame(55, OrganizationUsage::query()
            ->where('organization_id', $organization->id)
            ->where('metric', QuotaMetric::SourceFetches)
            ->whereDate('period_start', '2026-10-06')
            ->value('used'));

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::QuotaUsageReset->value,
            'reason' => 'False alarm metering',
        ]);

        $entry = AdminActionLog::query()->where('action', AdminAction::QuotaUsageReset)->sole();
        $this->assertSame(7, $entry->changes['used']['before']);
        $this->assertSame(0, $entry->changes['used']['after']);
        $this->assertSame('2026-10-07', $entry->changes['period_start']);
    }

    public function test_invalid_plan_metric_and_limit_are_rejected(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->patch(route('admin.organizations.plan.update', $organization), [
                'plan' => 'does-not-exist',
                'reason' => 'Bad plan',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('plan');

        $this->assertSame('free', $organization->fresh()->plan);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => 'not_a_metric',
                'limit' => 10,
                'reason' => 'Bad metric',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('metric');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => QuotaMetric::Members->value,
                'limit' => -1,
                'reason' => 'Negative limit',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('limit');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->post(route('admin.organizations.quota-overrides.store', $organization), [
                'metric' => QuotaMetric::Members->value,
                'reason' => 'Missing limit and unlimited',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('limit');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->post(route('admin.organizations.quota-usage-resets.store', $organization), [
                'metric' => QuotaMetric::Members->value,
                'reason' => 'Stock metrics cannot reset',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('metric');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.organizations.show', $organization))
            ->patch(route('admin.organizations.plan.update', $organization), [
                'plan' => 'unlimited',
                'reason' => 'x',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, OrganizationQuotaOverride::query()->count());
        $this->assertSame(0, AdminActionLog::query()->whereIn('action', [
            AdminAction::PlanChanged->value,
            AdminAction::QuotaOverrideSet->value,
            AdminAction::QuotaOverrideCleared->value,
            AdminAction::QuotaUsageReset->value,
        ])->count());
    }

    public function test_admin_organization_show_lists_quota_card(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free', 'name' => 'Quota Org']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'limit' => 250,
            'note' => 'custom deliveries',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.show', $organization))
            ->assertOk()
            ->assertSee('Quotas', false)
            ->assertSee('Change plan', false)
            ->assertSee('Source fetches', false)
            ->assertSee('custom deliveries', false)
            ->assertSee('Set override', false)
            ->assertSee('Reset current period usage', false)
            ->assertSee('Set period', false);
    }
}
