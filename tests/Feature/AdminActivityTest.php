<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationUsage;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\SecretRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_get_404_on_activity(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity'))
            ->assertNotFound();
    }

    public function test_activity_lists_runs_newest_first_with_org_link(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Alpha Org']);

        $older = $this->broadcastRunFor($organization, [
            'name' => 'Older Broadcast',
            'started_at' => now()->subHours(2),
            'status' => RunStatus::Completed,
        ]);
        $newer = $this->broadcastRunFor($organization, [
            'name' => 'Newer Broadcast',
            'started_at' => now()->subHour(),
            'status' => RunStatus::Failed,
            'error_detail' => 'Something broke.',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity'));

        $response->assertOk()
            ->assertSee('Needs attention', false)
            ->assertSee('Alpha Org', false)
            ->assertSee('Newer Broadcast', false)
            ->assertSee('Older Broadcast', false)
            ->assertSee(route('admin.organizations.show', $organization), false)
            ->assertSee('Something broke.', false);

        $html = $response->getContent();
        $newerPos = strpos($html, 'Newer Broadcast');
        $olderPos = strpos($html, 'Older Broadcast');
        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertLessThan($olderPos, $newerPos);

        $this->assertNotNull($older->getKey());
        $this->assertNotNull($newer->getKey());
    }

    public function test_status_filter_narrows_the_list(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();

        $this->broadcastRunFor($organization, [
            'name' => 'Failed Broadcast',
            'status' => RunStatus::Failed,
        ]);
        $this->broadcastRunFor($organization, [
            'name' => 'Completed Broadcast',
            'status' => RunStatus::Completed,
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity', ['status' => RunStatus::Failed->value]))
            ->assertOk()
            ->assertSee('Failed Broadcast', false)
            ->assertDontSee('Completed Broadcast', false);
    }

    public function test_organization_filter_narrows_the_list(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $alpha = Organization::factory()->ownedBy($owner)->create(['name' => 'Alpha Filter Org']);
        $beta = Organization::factory()->ownedBy($owner)->create(['name' => 'Beta Filter Org']);

        $this->broadcastRunFor($alpha, ['name' => 'Alpha Only Broadcast']);
        $this->broadcastRunFor($beta, ['name' => 'Beta Only Broadcast']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity', ['organization_id' => $alpha->id]))
            ->assertOk()
            ->assertSee('Alpha Only Broadcast', false)
            ->assertDontSee('Beta Only Broadcast', false);
    }

    public function test_source_type_filter_narrows_the_list(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();

        $this->broadcastRunFor($organization, [
            'name' => 'Rss Broadcast',
            'source_type' => 'rss',
        ]);
        $this->broadcastRunFor($organization, [
            'name' => 'Reddit Broadcast',
            'source_type' => 'reddit',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity', ['source_type' => 'rss']))
            ->assertOk()
            ->assertSee('Rss Broadcast', false)
            ->assertDontSee('Reddit Broadcast', false);
    }

    public function test_time_window_filter_narrows_the_list(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'UTC'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();

        $this->broadcastRunFor($organization, [
            'name' => 'Recent Broadcast',
            'started_at' => now()->subHours(2),
        ]);
        $this->broadcastRunFor($organization, [
            'name' => 'Stale Broadcast',
            'started_at' => now()->subDays(10),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity', ['window' => '24h']))
            ->assertOk()
            ->assertSee('Recent Broadcast', false)
            ->assertDontSee('Stale Broadcast', false);
    }

    public function test_out_of_quota_run_appears_with_its_reason(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Quota Org']);

        $this->broadcastRunFor($organization, [
            'name' => 'Quota Broadcast',
            'status' => RunStatus::QuotaExceeded,
            'error_detail' => 'Daily source fetch limit reached (0 of 0). Resets at 00:00 UTC.',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity', ['status' => RunStatus::QuotaExceeded->value]))
            ->assertOk()
            ->assertSee('Quota Broadcast', false)
            ->assertSee('Out of quota', false)
            ->assertSee('Daily source fetch limit reached (0 of 0). Resets at 00:00 UTC.', false);
    }

    public function test_credential_inside_error_detail_is_redacted(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();

        $token = '123456789:AABotTokenLeakHere1234567890';

        $this->broadcastRunFor($organization, [
            'name' => 'Leaky Broadcast',
            'status' => RunStatus::Failed,
            'error_detail' => "Telegram rejected bot {$token} for chat.",
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity'))
            ->assertOk()
            ->assertSee(SecretRedactor::REPLACEMENT, false)
            ->assertDontSee('AABotTokenLeakHere1234567890', false)
            ->assertDontSee($token, false);
    }

    public function test_needs_attention_summary_matches_underlying_records(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $this->travelTo(Carbon::parse('2026-10-07 15:00:00', 'UTC'));

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $noisy = Organization::factory()->ownedBy($owner)->create(['name' => 'Noisy Tenant']);

        $pausedProject = Project::factory()->for($noisy)->create();
        $pausedSource = Source::factory()->for($pausedProject)->create(['type' => 'rss']);
        $pausedDestination = Destination::factory()->for($pausedProject)->create();
        Broadcast::factory()->for($pausedProject)->create([
            'name' => 'Paused Route',
            'source_id' => $pausedSource->id,
            'destination_id' => $pausedDestination->id,
            'enabled' => false,
            'failure_pause_reason' => 'Too many consecutive failures.',
        ]);

        OrganizationUsage::query()->create([
            'organization_id' => $noisy->id,
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => now('UTC')->toDateString(),
            'used' => 200,
        ]);

        $run = $this->broadcastRunFor($noisy, [
            'name' => 'Noisy Run',
            'started_at' => now()->subHour(),
            'items_delivered' => 3,
        ]);

        $item = ContentItem::factory()->create([
            'project_id' => $run->broadcast->project_id,
            'source_id' => $run->broadcast->source_id,
        ]);
        ContentDelivery::factory()->create([
            'content_item_id' => $item->id,
            'broadcast_id' => $run->broadcast_id,
            'destination_id' => $run->broadcast->destination_id,
            'broadcast_run_id' => $run->id,
            'attempted_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.activity'));

        $response->assertOk()
            ->assertSee('Paused Route', false)
            ->assertSee('Too many consecutive failures.', false)
            ->assertSee('Noisy Tenant', false)
            ->assertSee('Source fetches', false)
            ->assertSee('200 / 200', false)
            ->assertSee('Top fetches (24h)', false)
            ->assertSee('Top deliveries (24h)', false);
    }

    /**
     * @param  array{name?: string, status?: RunStatus, started_at?: Carbon, error_detail?: string|null, source_type?: string, items_delivered?: int}  $overrides
     */
    private function broadcastRunFor(Organization $organization, array $overrides = []): BroadcastRun
    {
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create([
            'type' => $overrides['source_type'] ?? 'rss',
        ]);
        $destination = Destination::factory()->for($project)->create(['type' => 'telegram']);
        $broadcast = Broadcast::factory()->for($project)->create([
            'name' => $overrides['name'] ?? 'Broadcast '.uniqid(),
            'source_id' => $source->id,
            'destination_id' => $destination->id,
        ]);
        $projectRun = ProjectRun::factory()->for($project)->create([
            'started_at' => $overrides['started_at'] ?? now()->subMinute(),
            'status' => $overrides['status'] ?? RunStatus::Completed,
        ]);

        return BroadcastRun::factory()->create([
            'project_run_id' => $projectRun->id,
            'broadcast_id' => $broadcast->id,
            'status' => $overrides['status'] ?? RunStatus::Completed,
            'started_at' => $overrides['started_at'] ?? now()->subMinute(),
            'finished_at' => now(),
            'error_detail' => $overrides['error_detail'] ?? null,
            'items_delivered' => $overrides['items_delivered'] ?? 0,
        ]);
    }
}
