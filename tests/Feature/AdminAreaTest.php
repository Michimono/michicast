<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\RunStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_login_from_admin(): void
    {
        $this->get(route('admin.overview'))
            ->assertRedirect(route('session.create'));
    }

    public function test_a_logged_in_non_admin_gets_404_on_admin(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->actingAs($user)
            ->get(route('admin.overview'))
            ->assertNotFound();
    }

    public function test_an_admin_must_confirm_password_before_the_first_admin_page(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.overview'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_an_admin_reaches_overview_after_password_confirmation(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'));

        $response->assertOk()
            ->assertSee('Overview', false)
            ->assertHeader('X-Robots-Tag', 'noindex');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_admin_must_confirm_password_again_after_the_admin_timeout(): void
    {
        config([
            'michicast.platform_admin_emails' => ['admin@example.test'],
            'michicast.admin.password_timeout' => 900,
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time() - 901])
            ->get(route('admin.overview'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_confirming_password_then_returns_to_the_intended_admin_page(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->get(route('admin.overview'))
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($admin)
            ->from(route('password.confirm'))
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('admin.overview'));
    }

    public function test_admin_allowed_ips_blocks_and_allows(): void
    {
        config([
            'michicast.platform_admin_emails' => ['admin@example.test'],
            'michicast.admin.allowed_ips' => ['203.0.113.10', '198.51.100.0/24'],
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get(route('admin.overview'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get(route('admin.overview'))
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->get(route('admin.overview'))
            ->assertOk();
    }

    public function test_empty_admin_allowed_ips_does_not_restrict(): void
    {
        config([
            'michicast.platform_admin_emails' => ['admin@example.test'],
            'michicast.admin.allowed_ips' => [],
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get(route('admin.overview'))
            ->assertOk();
    }

    public function test_overview_shows_platform_counts(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        User::factory()->count(2)->create();

        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();
        Organization::factory()->ownedBy($owner)->create();

        Project::factory()->for($organization)->create(['status' => ProjectStatus::Active]);
        Project::factory()->for($organization)->create(['status' => ProjectStatus::Draft]);

        ProjectRun::factory()->for(
            Project::factory()->for($organization)->create(),
        )->create([
            'status' => RunStatus::Completed,
            'started_at' => now()->subHours(2),
        ]);

        ProjectRun::factory()->for(
            Project::factory()->for($organization)->create(),
        )->failed()->create([
            'started_at' => now()->subHours(3),
        ]);

        ProjectRun::factory()->for(
            Project::factory()->for($organization)->create(),
        )->create([
            'status' => RunStatus::Completed,
            'started_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'));

        $response->assertOk();
        $response->assertSee('4', false); // users: admin + 2 + owner
        $response->assertSee('2', false); // organizations
        $response->assertSee('1', false); // active broadcasts / completed / failed in 24h
        $response->assertSee('Completed', false);
        $response->assertSee('Failed', false);
    }

    public function test_admin_page_passes_strict_markup_rules(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc=)/i', $html);
    }

    public function test_admin_header_link_is_visible_only_to_platform_admins(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $member = User::factory()->create(['email' => 'member@example.test']);

        // Users without organizations are redirected from the index (#198), so
        // check the header on the create page, which everyone can open.
        $this->actingAs($admin)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertSee('href="'.route('admin.overview').'"', false)
            ->assertSee('>Admin</a>', false);

        $this->actingAs($member)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertDontSee('href="'.route('admin.overview').'"', false);
    }

    public function test_overview_shows_failed_jobs_when_the_table_exists(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            $this->markTestSkipped('failed_jobs table is not migrated in this environment.');
        }

        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSee('Failed jobs', false)
            ->assertSee('1', false);
    }
}
