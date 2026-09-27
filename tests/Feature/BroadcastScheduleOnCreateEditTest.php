<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastScheduleOnCreateEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_broadcast_persists_the_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);

        $this->actingAs($user)
            ->get(route('broadcasts.create', $organization))
            ->assertOk()
            ->assertSee('Schedule')
            ->assertSee('data-schedule-fields', false)
            ->assertSee('data-schedule-panel="daily weekly"', false)
            ->assertSee('data-schedule-panel="weekly"', false)
            ->assertSee('data-schedule-panel="interval"', false);

        $response = $this->actingAs($user)->post(route('broadcasts.store', $organization), [
            'name' => 'Morning Digest',
            'description' => 'Quiet feed.',
            'frequency' => 'daily',
            'time' => '15:00',
            'timezone' => '',
        ]);

        $project = $organization->projects()->sole();

        $response->assertRedirect(route('broadcasts.show', [$organization, $project]));
        $this->assertSame('daily', $project->fresh()->schedule['frequency']);
        $this->assertSame('15:00', $project->fresh()->schedule['time']);
        $this->assertTrue($project->fresh()->next_run_at->equalTo(Carbon::parse('2026-03-07 15:00:00', 'UTC')));
    }

    public function test_edit_updates_name_and_schedule_together(): void
    {
        $this->travelTo(Carbon::parse('2026-03-07 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->dailyAt('09:00')->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('Edit broadcast')
            ->assertSee('Schedule')
            ->assertSee('Old Name');

        $this->actingAs($user)
            ->patch(route('broadcasts.update', [$organization, $project]), [
                'name' => 'New Name',
                'description' => '',
                'frequency' => 'weekly',
                'time' => '18:00',
                'weekdays' => [1, 3],
                'timezone' => '',
            ])
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The broadcast has been updated.');

        $project->refresh();

        $this->assertSame('New Name', $project->name);
        $this->assertSame('weekly', $project->schedule['frequency']);
        $this->assertSame('18:00', $project->schedule['time']);
        $this->assertSame([1, 3], $project->schedule['weekdays']);
    }

    public function test_interval_schedule_on_create_only_needs_hours(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor, ['timezone' => 'UTC']);

        $this->actingAs($user)
            ->post(route('broadcasts.store', $organization), [
                'name' => 'Hourly Relay',
                'frequency' => 'interval',
                'hours' => 6,
                'timezone' => '',
            ])
            ->assertSessionHasNoErrors();

        $project = $organization->projects()->sole();

        $this->assertSame('interval', $project->schedule['frequency']);
        $this->assertSame(6, $project->schedule['hours']);
        $this->assertArrayNotHasKey('time', $project->schedule);
    }

    public function test_weekly_create_requires_weekdays(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('broadcasts.store', $organization), [
                'name' => 'Weekly Show',
                'frequency' => 'weekly',
                'time' => '09:00',
            ])
            ->assertSessionHasErrors('weekdays');
    }

    public function test_daily_create_requires_time(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);

        $this->actingAs($user)
            ->post(route('broadcasts.store', $organization), [
                'name' => 'Daily Show',
                'frequency' => 'daily',
            ])
            ->assertSessionHasErrors('time');
    }

    public function test_overview_points_edit_at_the_unified_form(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee(route('broadcasts.edit', [$organization, $project]), false)
            ->assertSee('Edit')
            ->assertDontSee('Set schedule')
            ->assertDontSee('schedule-drawer')
            ->assertSee('No schedule set');
    }

    public function test_legacy_schedule_edit_redirects_to_broadcast_edit(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcast-schedules.edit', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]));
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
