<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectRunner;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DueProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_marks_a_project_due_without_running_it_in_the_request(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $runner = new CountingProjectRunner;
        $this->app->instance(ProjectRunner::class, $runner);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The run is queued for the next scheduler tick, within about a minute.');

        $this->assertSame(0, $runner->runs);

        $project->refresh();

        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 10:00:00', 'UTC')));
        $this->assertNull($project->claimed_at);
    }

    public function test_a_repeated_press_does_not_double_dispatch(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $runner = new CountingProjectRunner;
        $this->app->instance(ProjectRunner::class, $runner);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ]);

        $this->actingAs($user)->post(route('due-broadcasts.store', [$organization, $project]))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('due-broadcasts.store', [$organization, $project]))->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 10:00:00', 'UTC')));
        $this->assertNull($project->claimed_at);
        $this->assertSame(0, $runner->runs);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame(1, $runner->runs);
        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    public function test_a_claimed_project_is_not_queued_again(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $project->claimed_at = now();
        $project->save();

        $this->actingAs($user)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The run is queued for the next scheduler tick, within about a minute.');

        $project->refresh();

        $this->assertTrue($project->claimed_at->equalTo(Carbon::parse('2026-03-15 10:00:00', 'UTC')));
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
    }

    public function test_an_editor_sees_run_now_on_an_active_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Run now')
            ->assertSee(route('immediate-broadcast-runs.store', [$organization, $project]), false);
    }

    public function test_a_draft_project_shows_run_now_alongside_settings(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('Run now')
            ->assertSee(route('immediate-broadcast-runs.store', [$organization, $project]), false);
    }

    public function test_a_viewer_cannot_mark_a_project_due(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('Run now');

        $this->actingAs($user)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertForbidden();

        $this->assertNull($project->refresh()->next_run_at);
    }

    public function test_guests_cannot_mark_a_project_due(): void
    {
        $project = Project::factory()->active()->create();

        $this->post(route('due-broadcasts.store', [$project->organization, $project]))
            ->assertRedirect(route('session.create'));
    }

    public function test_a_member_of_another_organization_cannot_mark_a_project_due(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $project = Project::factory()->for($organization)->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ]);

        $this->actingAs($outsider)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertNotFound();

        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    #[DataProvider('undispatchableStatuses')]
    public function test_only_an_active_project_can_be_marked_due(ProjectStatus $status): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->state([
            'status' => $status,
            'archived_from' => $status === ProjectStatus::Archived ? ProjectStatus::Active : null,
            'next_run_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
        ])->create();

        $this->actingAs($user)
            ->post(route('due-broadcasts.store', [$organization, $project]))
            ->assertRedirect()
            ->assertSessionHas('error', 'Only an active project can be marked due.');

        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    /**
     * @return array<string, array{ProjectStatus}>
     */
    public static function undispatchableStatuses(): array
    {
        return [
            'draft' => [ProjectStatus::Draft],
            'paused' => [ProjectStatus::Paused],
            'archived' => [ProjectStatus::Archived],
        ];
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

class CountingProjectRunner implements ProjectRunner
{
    public int $runs = 0;

    public function run(Project $project, ?CarbonInterface $deadline = null): bool
    {
        $this->runs++;

        return true;
    }
}
