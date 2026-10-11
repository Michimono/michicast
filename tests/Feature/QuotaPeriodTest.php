<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use App\Models\AdminActionLog;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationQuotaPeriod;
use App\Models\OrganizationUsage;
use App\Models\User;
use App\Support\QuotaMeter;
use App\Support\Quotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotaPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_boundaries_are_utc_calendar_periods(): void
    {
        // Thursday 1 October 2026, late evening UTC.
        $at = Carbon::parse('2026-10-01 23:30:00', 'UTC');

        $this->assertSame('2026-10-01', QuotaPeriod::Daily->startOf($at)->toDateString());
        $this->assertSame('2026-10-02 00:00', QuotaPeriod::Daily->resetsAt(QuotaPeriod::Daily->startOf($at))->format('Y-m-d H:i'));

        // The week that contains 1 October started on Monday 28 September.
        $this->assertSame('2026-09-28', QuotaPeriod::Weekly->startOf($at)->toDateString());
        $this->assertSame('2026-10-05 00:00', QuotaPeriod::Weekly->resetsAt(QuotaPeriod::Weekly->startOf($at))->format('Y-m-d H:i'));

        $this->assertSame('2026-10-01', QuotaPeriod::Monthly->startOf($at)->toDateString());
        $this->assertSame('2026-11-01 00:00', QuotaPeriod::Monthly->resetsAt(QuotaPeriod::Monthly->startOf($at))->format('Y-m-d H:i'));

        // A non-UTC instant is converted before taking the boundary.
        $madrid = Carbon::parse('2026-10-05 01:00:00', 'Europe/Madrid'); // Sunday 23:00 UTC

        $this->assertSame('2026-09-28', QuotaPeriod::Weekly->startOf($madrid)->toDateString());
    }

    public function test_plans_default_to_monthly_and_existing_rows_stay_daily(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $organization = Organization::factory()->create(['plan' => 'free']);

        $this->assertTrue(Schema::hasColumn('organization_usage', 'period'));
        $quotas = app(Quotas::class);

        foreach (['free', 'unlimited'] as $plan) {
            $organization->forceFill(['plan' => $plan])->save();
            $this->assertSame(QuotaPeriod::Monthly, $quotas->periodFor($organization, QuotaMetric::Deliveries));
            $this->assertSame(QuotaPeriod::Monthly, $quotas->periodFor($organization, QuotaMetric::SourceFetches));
        }

        // Missing config falls back to monthly too.
        config(['michicast.quotas.plan_periods' => []]);
        $this->assertSame(QuotaPeriod::Monthly, $quotas->periodFor($organization, QuotaMetric::Deliveries));

        // Rows written before #196 are daily counters and must not be read as
        // a monthly row, so the column default stays daily. They keep counting
        // for an organization that is set to daily.
        $organization->forceFill(['plan' => 'free'])->save();
        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'period' => QuotaPeriod::Daily,
        ]);

        // A row written before this change (no period column value) keeps counting as today's daily usage.
        OrganizationUsage::query()->insert([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries->value,
            'period_start' => '2026-10-06',
            'used' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $meter = app(QuotaMeter::class);

        $this->assertSame(QuotaPeriod::Daily, OrganizationUsage::query()->sole()->period);
        $this->assertSame(7, $meter->used($organization, QuotaMetric::Deliveries));
        $this->assertSame(8, $meter->consume($organization, QuotaMetric::Deliveries)->used);
    }

    public function test_plan_period_comes_from_config_and_the_admin_setting_wins(): void
    {
        config(['michicast.quotas.plan_periods.free.source_fetches' => 'daily']);

        $organization = Organization::factory()->create(['plan' => 'free']);
        $quotas = app(Quotas::class);

        $this->assertSame(QuotaPeriod::Daily, $quotas->periodFor($organization, QuotaMetric::SourceFetches));
        $this->assertSame(QuotaPeriod::Monthly, $quotas->periodFor($organization, QuotaMetric::Deliveries));

        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period' => QuotaPeriod::Weekly,
        ]);

        $this->assertSame(QuotaPeriod::Weekly, $quotas->periodFor($organization, QuotaMetric::SourceFetches));
        $this->assertSame(QuotaPeriod::Daily, $quotas->planPeriodFor($organization, QuotaMetric::SourceFetches));
    }

    public function test_weekly_usage_accumulates_across_days_and_resets_on_monday(): void
    {
        $organization = $this->organizationWith(QuotaMetric::Deliveries, QuotaPeriod::Weekly, limit: 3);
        $meter = app(QuotaMeter::class);

        // Wednesday, Friday and Sunday of the same week share one counter.
        foreach (['2026-10-07 09:00:00', '2026-10-09 09:00:00', '2026-10-11 23:59:00'] as $at) {
            $this->travelTo(Carbon::parse($at, 'UTC'));
            $this->assertTrue($meter->consume($organization, QuotaMetric::Deliveries)->allowed);
        }

        $refused = $meter->consume($organization, QuotaMetric::Deliveries);

        $this->assertFalse($refused->allowed);
        $this->assertSame(3, $refused->used);
        $this->assertSame(QuotaPeriod::Weekly, $refused->period);
        $this->assertTrue($refused->resetsAt->equalTo(Carbon::parse('2026-10-12 00:00:00', 'UTC')));
        $this->assertSame(
            'Weekly delivery limit reached (3 of 3). Resets at Mon 12 Oct 00:00 UTC.',
            $refused->message(QuotaMetric::Deliveries),
        );

        $this->travelTo(Carbon::parse('2026-10-12 00:00:01', 'UTC'));

        $this->assertSame(0, $meter->used($organization, QuotaMetric::Deliveries));
        $this->assertTrue($meter->consume($organization, QuotaMetric::Deliveries)->allowed);
        $this->assertSame(2, OrganizationUsage::query()->where('period', QuotaPeriod::Weekly)->count());
    }

    public function test_monthly_usage_resets_on_the_first(): void
    {
        $organization = $this->organizationWith(QuotaMetric::SourceFetches, QuotaPeriod::Monthly, limit: 2);
        $meter = app(QuotaMeter::class);

        $this->travelTo(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
        $meter->consume($organization, QuotaMetric::SourceFetches);

        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'UTC'));
        $meter->consume($organization, QuotaMetric::SourceFetches);

        $refused = $meter->consume($organization, QuotaMetric::SourceFetches);

        $this->assertFalse($refused->allowed);
        $this->assertSame('Monthly source fetch limit reached (2 of 2). Resets at Sun 1 Nov 00:00 UTC.', $refused->message(QuotaMetric::SourceFetches));

        $this->travelTo(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->assertTrue($meter->consume($organization, QuotaMetric::SourceFetches)->allowed);
        $this->assertSame(1, $meter->used($organization, QuotaMetric::SourceFetches));
    }

    public function test_switching_period_starts_a_fresh_counter_and_does_not_reuse_daily_rows(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'UTC')); // a Monday
        config(['michicast.quotas.plan_periods.free.deliveries' => 'daily']);

        $organization = Organization::factory()->create(['plan' => 'free']);
        $meter = app(QuotaMeter::class);

        $meter->consume($organization, QuotaMetric::Deliveries, 5);

        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'period' => QuotaPeriod::Weekly,
        ]);

        // The Monday daily row and the Monday weekly row are different counters.
        $this->assertSame(0, $meter->used($organization, QuotaMetric::Deliveries));
        $this->assertSame(1, $meter->consume($organization, QuotaMetric::Deliveries)->used);
        $this->assertSame(2, OrganizationUsage::query()->whereDate('period_start', '2026-10-05')->count());
    }

    public function test_reset_zeroes_only_the_current_weekly_period(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'UTC'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = $this->organizationWith(QuotaMetric::Deliveries, QuotaPeriod::Weekly);
        app(QuotaMeter::class)->consume($organization, QuotaMetric::Deliveries, 4);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.quota-usage-resets.store', $organization), [
                'metric' => QuotaMetric::Deliveries->value,
                'reason' => 'False alarm',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization));

        $this->assertSame(0, app(QuotaMeter::class)->used($organization, QuotaMetric::Deliveries));

        $entry = AdminActionLog::query()->where('action', AdminAction::QuotaUsageReset)->sole();

        $this->assertSame('weekly', $entry->changes['period']);
        $this->assertSame('2026-10-05', $entry->changes['period_start']);
    }

    public function test_admin_sets_and_clears_a_period_with_an_audit_entry(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);
        $quotas = app(Quotas::class);
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($admin)
            ->withSession($session)
            ->post(route('admin.organizations.quota-periods.store', $organization), [
                'metric' => QuotaMetric::Deliveries->value,
                'period' => 'weekly',
                'reason' => 'Customer wants weekly budgets',
            ])
            ->assertRedirect(route('admin.organizations.show', $organization))
            ->assertSessionHas('success', 'Deliveries now counts weekly.');

        $this->assertSame(QuotaPeriod::Weekly, $quotas->periodFor($organization, QuotaMetric::Deliveries));

        $set = AdminActionLog::query()->where('action', AdminAction::QuotaPeriodSet)->sole();

        $this->assertSame('Customer wants weekly budgets', $set->reason);
        $this->assertSame('deliveries', $set->changes['metric']);
        $this->assertSame(['before' => '(plan)', 'after' => 'weekly'], $set->changes['period_setting']);
        $this->assertSame(['before' => 'monthly', 'after' => 'weekly'], $set->changes['effective_period']);
        $this->assertSame('monthly', $set->changes['plan_period']);

        $this->actingAs($admin)
            ->withSession($session)
            ->post(route('admin.organizations.quota-periods.store', $organization), [
                'metric' => QuotaMetric::Deliveries->value,
                'period' => 'plan',
                'reason' => 'Back to the plan',
            ])
            ->assertSessionHas('success', 'Deliveries now counts monthly (plan default).');

        $this->assertSame(0, OrganizationQuotaPeriod::query()->count());
        $this->assertSame(QuotaPeriod::Monthly, $quotas->periodFor($organization, QuotaMetric::Deliveries));
        $this->assertSame(2, AdminActionLog::query()->where('action', AdminAction::QuotaPeriodSet)->count());

        $this->actingAs($admin)
            ->withSession($session)
            ->get(route('admin.organizations.show', $organization))
            ->assertOk()
            ->assertSee('Set period')
            ->assertSee('Plan default (Monthly)')
            ->assertSee('Usage this month');
    }

    public function test_admin_period_input_is_validated(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);
        $session = ['auth.password_confirmed_at' => time()];

        foreach ([
            [['metric' => 'deliveries', 'period' => 'hourly', 'reason' => 'Nope'], 'period'],
            [['metric' => 'members', 'period' => 'weekly', 'reason' => 'Nope'], 'metric'],
            [['metric' => 'deliveries', 'period' => 'weekly', 'reason' => ''], 'reason'],
        ] as [$input, $field]) {
            $this->actingAs($admin)
                ->withSession($session)
                ->post(route('admin.organizations.quota-periods.store', $organization), $input)
                ->assertSessionHasErrors($field);
        }

        $this->assertSame(0, OrganizationQuotaPeriod::query()->count());
        $this->assertSame(0, AdminActionLog::query()->where('action', AdminAction::QuotaPeriodSet)->count());
    }

    public function test_non_admins_get_404_on_the_period_endpoint(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);
        $organization = Organization::factory()->create(['plan' => 'free']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.organizations.quota-periods.store', $organization), [
                'metric' => QuotaMetric::Deliveries->value,
                'period' => 'weekly',
                'reason' => 'Should not work',
            ])
            ->assertNotFound();

        $this->assertSame(0, OrganizationQuotaPeriod::query()->count());
    }

    public function test_members_see_the_period_in_usage_copy(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));

        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create(['plan' => 'free']);

        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'period' => QuotaPeriod::Weekly,
        ]);
        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period' => QuotaPeriod::Monthly,
        ]);

        $this->actingAs($owner)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('this week')
            ->assertSee('this month')
            ->assertSee('2026-10-12T00:00:00', false)
            ->assertSee('2026-11-01T00:00:00', false);
    }

    private function organizationWith(QuotaMetric $metric, QuotaPeriod $period, ?int $limit = null): Organization
    {
        $organization = Organization::factory()->create(['plan' => 'free']);

        OrganizationQuotaPeriod::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => $metric,
            'period' => $period,
        ]);

        if ($limit !== null) {
            OrganizationQuotaOverride::query()->create([
                'organization_id' => $organization->getKey(),
                'metric' => $metric,
                'limit' => $limit,
            ]);
        }

        return $organization;
    }
}
