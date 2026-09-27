<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_save_a_daily_schedule_and_see_the_next_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '15:00',
                'timezone' => '',
            ])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The schedule has been saved.');

        $project->refresh();

        $this->assertSame('daily', $project->schedule['frequency']);
        $this->assertSame('15:00', $project->schedule['time']);
        $this->assertNull($project->timezone);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-07 15:00:00', 'UTC')));

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Saturday, March 7, 2026 at 3:00 PM UTC')
            ->assertSee('organization timezone');
    }

    public function test_editing_the_schedule_recalculates_the_next_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->dailyAt('15:00')->create();
        $project->refreshNextRunAt();
        $project->save();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '18:00',
            ])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));

        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-07 18:00:00', 'UTC')));
    }

    public function test_a_project_timezone_override_takes_precedence_over_the_organization(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'America/New_York']);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '15:00',
                'timezone' => 'Europe/Madrid',
            ])
            ->assertSessionHasNoErrors();

        $project->refresh();

        $this->assertSame('Europe/Madrid', $project->timezone);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-07 15:00:00', 'Europe/Madrid')->utc()));
        $this->assertFalse($project->next_run_at->equalTo(Carbon::parse('2026-03-07 15:00:00', 'America/New_York')->utc()));
    }

    public function test_previewing_shows_upcoming_runs_in_the_chosen_timezone_without_saving(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->patch(route('broadcast-schedule-previews.store', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '15:00',
                'timezone' => 'Europe/Madrid',
            ])
            ->assertOk()
            ->assertSee('Saturday, March 7, 2026 at 3:00 PM CET')
            ->assertSee('Europe/Madrid, the broadcast override');

        $this->assertNull($project->refresh()->schedule);
        $this->assertNull($project->next_run_at);
    }

    public function test_the_schedule_form_says_which_timezone_is_in_force(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'Europe/Madrid']);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Use organization timezone (Europe/Madrid)')
            ->assertSee('Europe/Madrid, the organization timezone');
    }

    public function test_changing_the_organization_timezone_recalculates_projects_without_an_override(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Owner, ['timezone' => 'UTC']);
        $inherited = Project::factory()->for($organization)->dailyAt('15:00')->create();
        $inherited->refreshNextRunAt();
        $inherited->save();
        $overridden = Project::factory()->for($organization)->dailyAt('15:00', 'Europe/Madrid')->create();
        $overridden->refreshNextRunAt();
        $overridden->save();
        $originalOverride = $overridden->next_run_at->copy();

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => $organization->name,
                'timezone' => 'America/New_York',
            ])
            ->assertSessionHasNoErrors();

        $inherited->refresh();
        $overridden->refresh();

        $this->assertTrue($inherited->next_run_at->equalTo(Carbon::parse('2026-03-07 15:00:00', 'America/New_York')->utc()));
        $this->assertTrue($overridden->next_run_at->equalTo($originalOverride));
    }

    public function test_changing_status_recalculates_the_next_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 10:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->dailyAt('15:00')->create();
        Broadcast::factory()->for($project)->create();
        $project->refreshNextRunAt();
        $project->save();

        $this->travelTo(Carbon::parse('2026-03-07 16:00:00', 'UTC'));

        $this->actingAs($user)
            ->post(route('active-broadcasts.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]));

        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-08 15:00:00', 'UTC')));
    }

    public function test_a_viewer_cannot_edit_the_schedule(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '09:00',
            ])
            ->assertForbidden();
    }

    public function test_a_member_of_another_organization_cannot_edit_the_schedule_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [$owner, $organization] = $this->memberHolding(Role::Owner);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($outsider)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '09:00',
            ])
            ->assertNotFound();
    }

    public function test_guests_cannot_edit_the_schedule(): void
    {
        $project = Project::factory()->create();

        $this->get(route('broadcasts.edit', [$project->organization, $project]))
            ->assertRedirect(route('session.create'));
    }

    public function test_an_unknown_timezone_identifier_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'daily',
                'time' => '09:00',
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertNull($project->refresh()->schedule);
    }

    public function test_a_weekly_schedule_requires_a_weekday(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->patch(route('broadcast-schedules.update', [$organization, $project]), [
                'frequency' => 'weekly',
                'time' => '09:00',
            ])
            ->assertSessionHasErrors('weekdays');
    }

    public function test_an_editor_is_offered_the_schedule_screen(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee(route('broadcasts.edit', [$organization, $project]), false)
            ->assertSee('No schedule set');
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
