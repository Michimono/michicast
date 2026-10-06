<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_admin_allows_a_user_whose_email_is_listed(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $other = User::factory()->create(['email' => 'member@example.test']);

        $this->assertTrue($admin->isPlatformAdmin());
        $this->assertTrue(Gate::forUser($admin)->allows('accessAdmin'));
        $this->assertFalse($other->isPlatformAdmin());
        $this->assertTrue(Gate::forUser($other)->denies('accessAdmin'));
    }

    public function test_access_admin_compares_emails_case_insensitively(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'Admin@Example.TEST']);

        $this->assertTrue($admin->isPlatformAdmin());
        $this->assertTrue(Gate::forUser($admin)->allows('accessAdmin'));
    }

    public function test_access_admin_denies_everyone_when_the_list_is_empty(): void
    {
        config(['michicast.platform_admin_emails' => []]);

        $user = User::factory()->create(['email' => 'anyone@example.test']);

        $this->assertFalse($user->isPlatformAdmin());
        $this->assertTrue(Gate::forUser($user)->denies('accessAdmin'));
    }

    public function test_changing_the_configured_list_changes_who_is_admin(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        config(['michicast.platform_admin_emails' => ['ops@example.test']]);
        $this->assertTrue(Gate::forUser($user)->allows('accessAdmin'));

        config(['michicast.platform_admin_emails' => ['other@example.test']]);
        $this->assertTrue(Gate::forUser($user)->denies('accessAdmin'));
    }

    public function test_platform_admin_who_is_not_a_member_gets_404_on_another_organizations_broadcast(): void
    {
        config(['michicast.platform_admin_emails' => ['admin@example.test']]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);
        $owner = User::factory()->create();
        $organization = Organization::factory()->ownedBy($owner)->create();
        $project = Project::factory()->for($organization)->create();

        $this->assertTrue(Gate::forUser($admin)->allows('accessAdmin'));

        $this->actingAs($admin)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertNotFound();
    }
}
