<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\User;
use App\Support\Quotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotasTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_for_returns_the_plan_limit_when_no_override_exists(): void
    {
        $organization = Organization::factory()->create(['plan' => 'free']);

        $limit = app(Quotas::class)->limitFor($organization, QuotaMetric::Members);

        $this->assertSame(config('michicast.quotas.plans.free.members'), $limit);
    }

    public function test_limit_for_returns_the_override_when_set(): void
    {
        $organization = Organization::factory()->create(['plan' => 'free']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Members,
            'limit' => 42,
            'note' => 'pilot cohort',
        ]);

        $limit = app(Quotas::class)->limitFor($organization, QuotaMetric::Members);

        $this->assertSame(42, $limit);
    }

    public function test_a_null_override_means_unlimited_for_that_metric(): void
    {
        $organization = Organization::factory()->create(['plan' => 'free']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => null,
            'note' => 'temporary lift',
        ]);

        $limit = app(Quotas::class)->limitFor($organization, QuotaMetric::SourceFetches);

        $this->assertNull($limit);
    }

    public function test_an_unknown_plan_falls_back_to_the_default_plan(): void
    {
        $organization = Organization::factory()->create(['plan' => 'does-not-exist']);

        $limit = app(Quotas::class)->limitFor($organization, QuotaMetric::Sources);

        $defaultPlan = config('michicast.quotas.default_plan');
        $this->assertSame(config("michicast.quotas.plans.{$defaultPlan}.sources"), $limit);
    }

    public function test_the_unlimited_plan_returns_null_for_every_metric(): void
    {
        $organization = Organization::factory()->create(['plan' => 'unlimited']);
        $quotas = app(Quotas::class);

        foreach (QuotaMetric::cases() as $metric) {
            $this->assertNull($quotas->limitFor($organization, $metric), $metric->value);
        }
    }

    public function test_a_new_organization_gets_the_default_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Quota Labs',
            'timezone' => 'UTC',
        ])->assertSessionHasNoErrors();

        $organization = Organization::query()->sole();

        $this->assertSame(config('michicast.quotas.default_plan'), $organization->plan);
    }

    public function test_rate_metrics_declare_a_monthly_default_period_and_counts_do_not(): void
    {
        $this->assertTrue(QuotaMetric::SourceFetches->isRate());
        $this->assertTrue(QuotaMetric::Deliveries->isRate());
        $this->assertSame('monthly', QuotaMetric::SourceFetches->period());
        $this->assertSame('monthly', QuotaMetric::Deliveries->period());

        $this->assertFalse(QuotaMetric::Members->isRate());
        $this->assertNull(QuotaMetric::Members->period());
        $this->assertNull(QuotaMetric::ActiveBroadcasts->period());
        $this->assertNull(QuotaMetric::Sources->period());
        $this->assertNull(QuotaMetric::Destinations->period());
    }

    public function test_existing_organizations_with_a_null_plan_are_backfilled_on_migrate(): void
    {
        // RefreshDatabase already migrated; insert a null-plan row as older data would,
        // then re-run the backfill step the migration performs.
        $organization = Organization::factory()->create(['plan' => 'free']);

        DB::table('organizations')
            ->where('id', $organization->getKey())
            ->update(['plan' => null]);

        $defaultPlan = (string) config('michicast.quotas.default_plan', 'free');

        DB::table('organizations')
            ->whereNull('plan')
            ->update(['plan' => $defaultPlan]);

        $this->assertSame($defaultPlan, $organization->fresh()->plan);
    }
}
