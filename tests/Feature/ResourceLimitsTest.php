<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationQuotaOverride;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\ResourceQuotas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResourceLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_organization_is_refused_at_the_per_user_limit_and_allowed_below_it(): void
    {
        config(['michicast.quotas.organizations_per_user' => 2]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'First', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Second', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Organization::query()->count());

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Third', 'timezone' => 'UTC'])
            ->assertSessionHasErrors([
                'name' => 'You can own at most 2 organizations.',
            ]);

        $this->assertSame(2, Organization::query()->count());
    }

    public function test_platform_admins_are_not_blocked_by_the_per_user_organization_limit(): void
    {
        config([
            'michicast.quotas.organizations_per_user' => 1,
            'michicast.platform_admin_emails' => ['admin@example.test'],
        ]);

        $admin = User::factory()->create(['email' => 'admin@example.test']);

        Organization::factory()->ownedBy($admin)->create();

        $this->actingAs($admin)
            ->post(route('organizations.store'), ['name' => 'Admin Extra', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $admin->memberships()->where('role', Role::Owner)->count());
    }

    public function test_creating_an_active_broadcast_is_refused_at_the_limit_and_allowed_below_it(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::ActiveBroadcasts, 1);

        $first = $this->projectWithPipeline($organization);
        $this->assertTrue($first->broadcasts()->sole()->enabled);

        $second = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($second)->create();
        $destination = Destination::factory()->for($second)->create();

        $this->actingAs($user)
            ->post(route('broadcast-pipelines.store', [$organization, $second]), [
                'name' => 'Over the line',
                'source_id' => $source->getKey(),
                'destination_id' => $destination->getKey(),
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $second->broadcasts()->count());
        $this->assertSame(
            1,
            app(ResourceQuotas::class)->count($organization, QuotaMetric::ActiveBroadcasts),
        );
    }

    public function test_enabling_a_broadcast_is_refused_at_the_active_broadcasts_limit(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::ActiveBroadcasts, 1);

        $active = $this->projectWithPipeline($organization)->broadcasts()->sole();
        $this->assertTrue($active->enabled);

        $other = Broadcast::factory()
            ->for(Project::factory()->for($organization))
            ->disabled()
            ->create();

        $this->actingAs($user)
            ->post(route('enabled-flows.store', [$organization, $other]))
            ->assertSessionHasErrors('enabled');

        $this->assertFalse($other->refresh()->enabled);
    }

    public function test_archived_broadcasts_do_not_count_toward_active_broadcasts(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::ActiveBroadcasts, 1);

        $broadcast = $this->projectWithPipeline($organization)->broadcasts()->sole();
        $broadcast->archive();

        $this->assertSame(
            0,
            app(ResourceQuotas::class)->count($organization, QuotaMetric::ActiveBroadcasts),
        );

        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('broadcast-pipelines.store', [$organization, $project]), [
                'name' => 'Fills the freed slot',
                'source_id' => $source->getKey(),
                'destination_id' => $destination->getKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $project->broadcasts()->count());
        $this->assertTrue($project->broadcasts()->sole()->enabled);
    }

    public function test_creating_a_source_is_refused_at_the_limit_and_allowed_below_it(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::Sources, 1);

        Source::factory()->for(Project::factory()->for($organization))->create();

        $open = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $open]), [
                'name' => 'One too many',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $open->sources()->count());

        $this->override($organization, QuotaMetric::Sources, 2);

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $open]), [
                'name' => 'Allowed feed',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $open->sources()->count());
    }

    public function test_creating_a_destination_is_refused_at_the_limit_and_allowed_below_it(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::Destinations, 1);

        Destination::factory()->for(Project::factory()->for($organization))->create();

        $open = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-destinations.store', [$organization, $open]), [
                'name' => 'One too many',
                'type' => 'recording',
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => 'secret'],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $open->destinations()->count());

        $this->override($organization, QuotaMetric::Destinations, 2);

        $this->actingAs($user)
            ->post(route('broadcast-destinations.store', [$organization, $open]), [
                'name' => 'Allowed channel',
                'type' => 'recording',
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => 'secret'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $open->destinations()->count());
    }

    public function test_inviting_a_member_is_refused_at_the_limit_and_allowed_below_it(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->ownerInOrg();
        $this->override($organization, QuotaMetric::Members, 2);

        // Owner already counts as one member.
        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'editor@example.test',
                'role' => Role::Editor->value,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'viewer@example.test',
                'role' => Role::Viewer->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $organization->invitations()->pending()->count());
    }

    public function test_pending_invitations_count_toward_members(): void
    {
        Mail::fake();

        [$owner, $organization] = $this->ownerInOrg();
        $this->override($organization, QuotaMetric::Members, 2);

        OrganizationInvitation::factory()->for($organization)->create([
            'invited_by' => $owner->getKey(),
            'email' => 'pending@example.test',
        ]);

        $this->assertSame(
            2,
            app(ResourceQuotas::class)->count($organization, QuotaMetric::Members),
        );

        $this->actingAs($owner)
            ->post(route('invitations.store', $organization), [
                'email' => 'another@example.test',
                'role' => Role::Viewer->value,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, $organization->invitations()->pending()->count());
    }

    public function test_a_lowered_limit_does_not_touch_existing_records(): void
    {
        [$user, $organization] = $this->editorInOrg();
        $this->override($organization, QuotaMetric::Sources, 3);

        $projects = Project::factory()->for($organization)->count(3)->create();
        $sources = $projects->map(
            fn (Project $project) => Source::factory()->for($project)->create(['name' => 'Keep '.$project->getKey()]),
        );

        $this->override($organization, QuotaMetric::Sources, 1);

        foreach ($sources as $source) {
            $this->assertDatabaseHas('sources', ['id' => $source->getKey()]);
            $this->assertTrue($source->fresh()->isRunnable());
        }

        $open = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-sources.store', [$organization, $open]), [
                'name' => 'Blocked by lowered limit',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $open->sources()->count());
        $this->assertSame(3, $organization->sources()->count());
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function editorInOrg(): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, Role::Editor)->create()];
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function ownerInOrg(): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->ownedBy($user)->create()];
    }

    private function override(Organization $organization, QuotaMetric $metric, ?int $limit): void
    {
        OrganizationQuotaOverride::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'metric' => $metric,
            ],
            [
                'limit' => $limit,
                'note' => 'resource limits test',
            ],
        );
    }

    private function projectWithPipeline(Organization $organization): Project
    {
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();

        Broadcast::factory()->for($project)->create([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => true,
        ]);

        return $project;
    }
}
