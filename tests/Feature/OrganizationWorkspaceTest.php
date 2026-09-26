<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_organization_workspace_points_at_new_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Nothing to chase yet')
            ->assertSee('+ New broadcast')
            ->assertSee(route('broadcasts.create', $organization), false)
            ->assertDontSee('Nothing to manage yet')
            ->assertDontSee('viewBox="0 0 64 10"', false);
    }

    public function test_a_configured_workspace_lists_project_cards_with_counts_and_runs(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 15:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $organization->forceFill(['timezone' => 'Europe/Madrid'])->save();

        $active = Project::factory()->for($organization)->active()->dailyAt('09:00', 'Europe/Madrid')->create([
            'name' => 'Pokemon TCGP News',
            'next_run_at' => Carbon::parse('2026-03-16 08:00:00', 'UTC'),
        ]);
        Source::factory()->for($active)->count(2)->create();
        Source::factory()->for($active)->create(['archived_at' => now()]);
        Destination::factory()->for($active)->create();
        Broadcast::factory()->create([
            'project_id' => $active->getKey(),
            'source_id' => $active->sources()->notArchived()->value('id'),
            'destination_id' => $active->destinations()->notArchived()->value('id'),
        ]);
        $finishedAt = now()->subHour();
        ProjectRun::factory()->for($active)->failed()->create([
            'started_at' => $finishedAt->copy()->subMinute(),
            'finished_at' => $finishedAt,
        ]);

        $paused = Project::factory()->for($organization)->paused()->create(['name' => 'Paused Digest']);
        Project::factory()->for($organization)->archived()->create(['name' => 'Old Archive']);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->actingAs($user)
            ->get(route('organizations.show', $organization));

        $response->assertOk();
        $response->assertSee('Pokemon TCGP News');
        $response->assertSee('Paused Digest');
        $response->assertDontSee('Old Archive');
        $response->assertSee('Active');
        $response->assertSee('Paused');
        $response->assertSeeInOrder(['Source', '2', 'Destination', '1']);
        $response->assertSee('Europe/Madrid');
        $response->assertSee('Failed');
        $response->assertSee($finishedAt->diffForHumans());
        $response->assertSee('Never run');
        $response->assertSee(route('broadcasts.show', [$organization, $active]), false);
        $response->assertSee('View broadcast');
        $response->assertDontSee('aria-label="Open Pokemon TCGP News"', false);
        $response->assertSee('has-[details[open]]:z-30', false);
        $response->assertSee('bg-paper-raised py-1 shadow-md', false);
        $response->assertSee('+ New broadcast');
        $response->assertSee('Pause');
        $response->assertSee('Resume');
        $response->assertSee('Archive');
        $response->assertSee('Edit');
        $response->assertSee('viewBox="0 0 64 10"', false);
        $response->assertSee('M2 10 10 1 18 10', false);
        $this->assertSame(2, substr_count($response->getContent(), 'viewBox="0 0 64 10"'));
        $this->assertSame(2, substr_count($response->getContent(), 'View broadcast'));
        $this->assertLessThan(20, $queries);
    }

    public function test_card_menu_pause_returns_to_the_organization_workspace(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create(['name' => 'Live Feed']);

        $this->actingAs($user)
            ->from(route('organizations.show', $organization))
            ->post(route('paused-broadcasts.store', [$organization, $project]), [
                'return' => 'organization',
            ])
            ->assertRedirect(route('organizations.show', $organization))
            ->assertSessionHas('success', 'The broadcast has been paused.');

        $this->assertSame(ProjectStatus::Paused, $project->refresh()->status);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Resume')
            ->assertDontSee('>Pause</button>', false);
    }

    public function test_card_menu_resume_and_archive_work_from_the_workspace(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $paused = Project::factory()->for($organization)->paused()->create(['name' => 'Waiting']);
        $active = Project::factory()->for($organization)->active()->create(['name' => 'Running']);

        $this->actingAs($user)
            ->delete(route('paused-broadcasts.destroy', [$organization, $paused]), [
                'return' => 'organization',
            ])
            ->assertRedirect(route('organizations.show', $organization));

        $this->assertSame(ProjectStatus::Active, $paused->refresh()->status);

        $this->actingAs($user)
            ->post(route('archived-broadcasts.store', [$organization, $active]), [
                'return' => 'organization',
            ])
            ->assertRedirect(route('organizations.show', $organization));

        $this->assertSame(ProjectStatus::Archived, $active->refresh()->status);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Waiting')
            ->assertDontSee('Running');
    }

    public function test_a_viewer_sees_cards_but_cannot_mutate_from_the_workspace(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create(['name' => 'Read Only News']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Read Only News')
            ->assertSee(route('broadcasts.show', [$organization, $project]), false)
            ->assertSee('View broadcast')
            ->assertDontSee('+ New broadcast')
            ->assertDontSee('Actions for Read Only News')
            ->assertDontSee('>Edit</a>', false)
            ->assertDontSee('>Pause</button>', false)
            ->assertDontSee('>Archive</button>', false);

        $this->actingAs($user)
            ->post(route('paused-broadcasts.store', [$organization, $project]), [
                'return' => 'organization',
            ])
            ->assertForbidden();

        $this->assertSame(ProjectStatus::Active, $project->refresh()->status);
    }

    public function test_pause_without_return_still_lands_on_the_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('paused-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
