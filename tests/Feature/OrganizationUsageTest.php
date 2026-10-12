<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationUsage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover daily counting; plans default to monthly (#196).
        $daily = ['source_fetches' => 'daily', 'deliveries' => 'daily'];
        config(['michicast.quotas.plan_periods' => ['free' => $daily, 'unlimited' => $daily]]);
    }

    public function test_the_usage_card_shows_used_and_limit_for_an_organization_with_usage(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 15:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Viewer, ['plan' => 'free']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 200,
            'note' => 'test',
        ]);

        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => '2026-10-06',
            'used' => 47,
        ]);

        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'period_start' => '2026-10-06',
            'used' => 12,
        ]);

        $deliveriesLimit = (int) config('michicast.quotas.plans.free.deliveries');

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('id="usage"', false)
            ->assertSee('Usage')
            ->assertSee('Plan:')
            ->assertSee('Free')
            ->assertSee('Source fetches')
            ->assertSee('47')
            ->assertSee('200')
            ->assertSee('Deliveries')
            ->assertSee('12')
            ->assertSee((string) $deliveriesLimit)
            ->assertSee('Active broadcasts')
            ->assertSee('Sources')
            ->assertSee('Destinations')
            ->assertSee('Members')
            ->assertSee('Resets');
    }

    public function test_the_banner_appears_when_a_volume_metric_is_exhausted_and_not_otherwise(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 15:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['plan' => 'free']);
        $project = Project::factory()->for($organization)->create(['name' => 'News Digest']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertDontSee('A usage quota is exhausted', false)
            ->assertDontSee('View usage');

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('A usage quota is exhausted', false);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 5,
            'note' => 'test cap',
        ]);

        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => '2026-10-06',
            'used' => 5,
        ]);

        $usageUrl = route('organization-settings.edit', $organization).'#usage';

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('A usage quota is exhausted')
            ->assertSee('View usage')
            ->assertSee($usageUrl, false);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('A usage quota is exhausted')
            ->assertSee($usageUrl, false);
    }

    #[DataProvider('memberRoles')]
    public function test_every_member_can_open_the_usage_card(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role, ['plan' => 'free']);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('id="usage"', false)
            ->assertSee('Usage');
    }

    public function test_a_non_member_gets_404_for_organization_settings(): void
    {
        $outsider = User::factory()->create();
        Organization::factory()->ownedBy($outsider)->create();
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($outsider)
            ->get(route('organization-settings.edit', $foreign))
            ->assertNotFound();
    }

    public function test_a_viewer_sees_usage_but_not_the_settings_form(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer, [
            'name' => 'Michimono Labs',
            'timezone' => 'Europe/Madrid',
        ]);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('Usage')
            ->assertDontSee('Save settings')
            ->assertDontSee('Organization name')
            ->assertDontSee('This timezone drives scheduling');
    }

    public function test_unlimited_metrics_are_labelled_as_unlimited(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, ['plan' => 'unlimited']);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('Unlimited')
            ->assertSee('Unlimited');
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function memberRoles(): array
    {
        return [
            'owner' => [Role::Owner],
            'admin' => [Role::Admin],
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role, array $attributes = []): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create($attributes)];
    }
}
