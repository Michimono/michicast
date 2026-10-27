<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\ExecutingProjectRunner;
use App\Support\ProjectRunner;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SourceSchedulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visual_broadcast_only_runs_the_sources_that_are_due(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$project, $hourly, $evening] = $this->visualWithTwoSources();
        $runner = $this->recordingRunner();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame([[(int) $hourly->getKey()]], $runner->calls);

        $this->assertTrue($hourly->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-15 11:00:00', 'UTC')));
        $this->assertTrue($evening->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-15 18:00:00', 'UTC')));
        $project->refresh();
        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 11:00:00', 'UTC')));
    }

    public function test_a_run_with_no_source_due_reads_every_route(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$project, $hourly] = $this->visualWithTwoSources();
        $hourly->next_run_at = Carbon::parse('2026-03-15 10:30:00', 'UTC');
        $hourly->save();
        $project->next_run_at = now()->subMinute();
        $project->save();
        $runner = $this->recordingRunner();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $this->assertSame([null], $runner->calls);
    }

    public function test_the_runner_only_reads_routes_of_the_given_sources(): void
    {
        [$project, $hourly, $evening] = $this->visualWithTwoSources();

        app(ExecutingProjectRunner::class)->run($project, null, [(int) $hourly->getKey()]);

        $ran = BroadcastRun::query()->pluck('broadcast_id')->map(fn (mixed $id): int => (int) $id)->all();
        $expected = Broadcast::query()->where('source_id', $hourly->getKey())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->assertSame($expected, $ran);
        $this->assertFalse(BroadcastRun::query()->whereIn('broadcast_id', Broadcast::query()->where('source_id', $evening->getKey())->select('id'))->exists());
    }

    public function test_activating_schedules_each_source_and_falls_back_to_the_broadcast_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->visual()->dailyAt('09:00')->create();
        $follows = Source::factory()->for($project)->create();
        $own = Source::factory()->for($project)->create(['schedule' => ['frequency' => 'interval', 'hours' => 3]]);
        Broadcast::factory()->create(['project_id' => $project->getKey(), 'source_id' => $follows->getKey()]);
        Broadcast::factory()->create(['project_id' => $project->getKey(), 'source_id' => $own->getKey()]);
        $unrouted = Source::factory()->for($project)->create(['next_run_at' => Carbon::parse('2026-03-01 09:00:00', 'UTC')]);

        $project->activate();

        $this->assertTrue($follows->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
        $this->assertTrue($own->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-15 13:00:00', 'UTC')));
        $this->assertNull($unrouted->refresh()->next_run_at);
        $this->assertTrue($project->refresh()->next_run_at->equalTo(Carbon::parse('2026-03-15 13:00:00', 'UTC')));
    }

    public function test_editing_a_source_schedule_from_the_editor_reschedules_it_and_the_broadcast(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$project, $hourly, $evening, $user, $organization] = $this->visualWithTwoSources();

        $this->actingAs($user)
            ->patchJson(route('sources.update', [$organization, $evening]), [
                'name' => 'Evening digest',
                'config' => $evening->config,
                'schedule' => ['frequency' => 'interval', 'hours' => 2],
            ])
            ->assertOk()
            ->assertJsonPath('source.schedule.frequency', 'interval')
            ->assertJsonPath('source.schedule_summary', 'Every 2 hours')
            ->assertJsonPath('source.follows_broadcast_schedule', false)
            ->assertJsonPath('project.id', $project->getKey());

        $evening->refresh();
        $this->assertEquals(['frequency' => 'interval', 'hours' => 2], $evening->schedule);
        $this->assertTrue($evening->next_run_at->equalTo(Carbon::parse('2026-03-15 12:00:00', 'UTC')));
    }

    public function test_creating_a_source_inline_keeps_its_own_schedule(): void
    {
        [$project, , , $user, $organization] = $this->visualWithTwoSources();

        $this->actingAs($user)
            ->postJson(route('broadcast-sources.store', [$organization, $project]), [
                'name' => 'Weekly roundup',
                'type' => 'fake',
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
                'schedule' => ['frequency' => 'weekly', 'time' => '08:30', 'weekdays' => [5, 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('source.schedule_summary', 'Weekly on Monday and Friday at 8:30');

        $source = $project->sources()->where('name', 'Weekly roundup')->sole();
        $this->assertEquals(['frequency' => 'weekly', 'time' => '08:30', 'weekdays' => [1, 5]], $source->schedule);
    }

    public function test_an_incomplete_schedule_is_rejected(): void
    {
        [, , $evening, $user, $organization] = $this->visualWithTwoSources();

        $this->actingAs($user)
            ->patchJson(route('sources.update', [$organization, $evening]), [
                'name' => 'Evening digest',
                'config' => $evening->config,
                'schedule' => ['frequency' => 'weekly', 'time' => '08:30'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule.weekdays']);
    }

    public function test_simple_broadcasts_keep_a_single_schedule(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->dailyAt('09:00')->create();
        $source = Source::factory()->for($project)->create();

        $this->actingAs($user)
            ->patchJson(route('sources.update', [$organization, $source]), [
                'name' => 'Feed',
                'config' => $source->config,
                'schedule' => ['frequency' => 'interval', 'hours' => 2],
            ])
            ->assertOk();

        $this->assertNull($source->refresh()->schedule);
        $this->assertNull($source->next_run_at);
    }

    /**
     * An active visual broadcast in UTC: an hourly source due at 9:30 and an
     * evening source next due at 18:00, each routed to its own destination.
     *
     * @return array{Project, Source, Source, User, Organization}
     */
    private function visualWithTwoSources(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create(['timezone' => 'UTC']);
        $project = Project::factory()->for($organization)->visual()->active()->dailyAt('09:00')->create([
            'next_run_at' => Carbon::parse('2026-03-15 09:30:00', 'UTC'),
        ]);
        $hourly = Source::factory()->for($project)->create([
            'name' => 'Hourly feed',
            'schedule' => ['frequency' => 'interval', 'hours' => 1],
            'next_run_at' => Carbon::parse('2026-03-15 09:30:00', 'UTC'),
        ]);
        $evening = Source::factory()->for($project)->create([
            'name' => 'Evening feed',
            'schedule' => ['frequency' => 'daily', 'time' => '18:00'],
            'next_run_at' => Carbon::parse('2026-03-15 18:00:00', 'UTC'),
        ]);

        foreach ([$hourly, $evening] as $source) {
            Broadcast::factory()->create([
                'project_id' => $project->getKey(),
                'source_id' => $source->getKey(),
                'destination_id' => Destination::factory()->for($project)->create()->getKey(),
            ]);
        }

        return [$project, $hourly, $evening, $user, $organization];
    }

    private function recordingRunner(): SourceRecordingRunner
    {
        $runner = new SourceRecordingRunner;
        $this->app->instance(ProjectRunner::class, $runner);

        return $runner;
    }
}

class SourceRecordingRunner implements ProjectRunner
{
    /**
     * @var list<list<int>|null>
     */
    public array $calls = [];

    public function run(Project $project, ?CarbonInterface $deadline = null, ?array $sourceIds = null): bool
    {
        $this->calls[] = $sourceIds;

        return true;
    }
}
