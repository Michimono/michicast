<?php

namespace Tests\Feature;

use App\Enums\AdminAction;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\AdminActionLog;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admins_get_404_on_each_directory_route(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $member = User::factory()->create(['email' => 'member@example.test']);
        $target = User::factory()->create(['email' => 'target@example.test']);
        $organization = Organization::factory()->ownedBy($target)->create();

        $routes = [
            route('admin.users.index'),
            route('admin.users.show', $target),
            route('admin.organizations.index'),
            route('admin.organizations.show', $organization),
        ];

        foreach ($routes as $url) {
            $this->actingAs($member)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->get($url)
                ->assertNotFound();
        }
    }

    public function test_user_list_search_finds_by_partial_email(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test', 'name' => 'Ada Admin']);
        User::factory()->create(['email' => 'needle-user@example.test', 'name' => 'Needle']);
        User::factory()->create(['email' => 'other@example.test', 'name' => 'Other']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.index', ['q' => 'needle-user']))
            ->assertOk()
            ->assertSee('needle-user@example.test', false)
            ->assertSee('Needle', false)
            ->assertDontSee('other@example.test', false);
    }

    public function test_organization_list_search_finds_by_partial_name(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();

        Organization::factory()->ownedBy($owner)->create([
            'name' => 'Needle Labs',
            'slug' => 'needle-labs',
        ]);
        Organization::factory()->ownedBy($owner)->create([
            'name' => 'Other Co',
            'slug' => 'other-co',
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.index', ['q' => 'Needle']))
            ->assertOk()
            ->assertSee('Needle Labs', false)
            ->assertDontSee('Other Co', false);
    }

    public function test_user_show_lists_memberships_sessions_and_audit_without_payload(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $user = User::factory()->create([
            'email' => 'subject@example.test',
            'name' => 'Subject User',
        ]);
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Subject Org']);

        DB::table('sessions')->insert([
            'id' => 'session-subject-1',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.50',
            'user_agent' => 'MichicastTestAgent/1.0',
            'payload' => 'SECRET_SESSION_PAYLOAD_SHOULD_NOT_APPEAR',
            'last_activity' => now()->timestamp,
        ]);

        AdminActionLog::factory()->forSubject($admin, $user)->create([
            'action' => AdminAction::SignedIn,
            'reason' => 'looked-at-subject',
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.users.show', $user));

        $response->assertOk()
            ->assertSee('Subject User', false)
            ->assertSee('subject@example.test', false)
            ->assertSee('Subject Org', false)
            ->assertSee(Role::Owner->label(), false)
            ->assertSee('203.0.113.50', false)
            ->assertSee('MichicastTestAgent/1.0', false)
            ->assertSee('looked-at-subject', false)
            ->assertDontSee('SECRET_SESSION_PAYLOAD_SHOULD_NOT_APPEAR', false);
    }

    public function test_organization_show_never_renders_destination_credentials(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Cred Org']);
        $project = Project::factory()->for($organization)->create([
            'name' => 'Cred Broadcast',
            'status' => ProjectStatus::Active,
        ]);
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create([
            'credentials' => ['token' => 'super-secret-admin-directory-token'],
        ]);
        Broadcast::factory()->for($project)->create([
            'source_id' => $source->id,
            'destination_id' => $destination->id,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.show', $organization));

        $response->assertOk()
            ->assertSee('Cred Org', false)
            ->assertSee('Cred Broadcast', false)
            ->assertDontSee('super-secret-admin-directory-token', false);
    }

    public function test_organization_show_lists_members_broadcast_health_usage_and_runs(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create(['name' => 'Org Owner', 'email' => 'owner@example.test']);
        $organization = Organization::factory()->ownedBy($owner)->create([
            'name' => 'Health Org',
            'plan' => 'free',
        ]);
        $project = Project::factory()->for($organization)->create([
            'name' => 'Health Broadcast',
            'status' => ProjectStatus::Active,
        ]);
        ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subHour(),
            'items_delivered' => 3,
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.show', $organization));

        $response->assertOk()
            ->assertSee('Health Org', false)
            ->assertSee('Org Owner', false)
            ->assertSee('owner@example.test', false)
            ->assertSee('Health Broadcast', false)
            ->assertSee('Active', false)
            ->assertSee('Plan:', false)
            ->assertSee('Usage', false);
    }

    public function test_organization_index_shows_counts_via_with_count(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create(['name' => 'Count Org']);
        $project = Project::factory()->for($organization)->create([
            'status' => ProjectStatus::Active,
        ]);
        ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subHours(2),
        ]);

        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->for($project)->create([
            'source_id' => $source->id,
            'destination_id' => $destination->id,
        ]);
        $item = ContentItem::factory()->for($project)->create();
        ContentDelivery::factory()->create([
            'content_item_id' => $item->id,
            'broadcast_id' => $broadcast->id,
            'destination_id' => $destination->id,
            'attempted_at' => now()->subHours(3),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.organizations.index', ['q' => 'Count Org']))
            ->assertOk()
            ->assertSee('Count Org', false)
            ->assertSee('1', false);
    }

    public function test_admin_sidebar_links_to_users_and_organizations(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('href="'.route('admin.users.index').'"', false)
            ->assertSee('href="'.route('admin.organizations.index').'"', false);
    }
}
