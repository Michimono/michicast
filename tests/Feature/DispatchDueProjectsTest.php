<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\ProjectRunner;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class DispatchDueProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_due_active_projects_and_skips_projects_that_are_not_due(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $due = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $notDue = Project::factory()->due(Carbon::parse('2026-03-15 11:00:00', 'UTC'))->create();
        $unscheduled = Project::factory()->active()->create(['next_run_at' => null]);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $due->refresh();
        $notDue->refresh();
        $unscheduled->refresh();

        $this->assertTrue($due->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
        $this->assertNull($due->claimed_at);
        $this->assertTrue($notDue->next_run_at->equalTo(Carbon::parse('2026-03-15 11:00:00', 'UTC')));
        $this->assertNull($notDue->claimed_at);
        $this->assertNull($unscheduled->next_run_at);
        $this->assertNull($unscheduled->claimed_at);
    }

    #[DataProvider('undispatchableStatuses')]
    public function test_does_not_dispatch_projects_that_are_not_active(ProjectStatus $status): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->state([
            'status' => $status,
            'archived_from' => $status === ProjectStatus::Archived ? ProjectStatus::Active : null,
            'schedule' => [
                'frequency' => 'daily',
                'time' => '09:00',
            ],
            'next_run_at' => Carbon::parse('2026-03-15 09:00:00', 'UTC'),
        ])->create();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $project->refresh();

        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
        $this->assertNull($project->claimed_at);
    }

    public function test_a_second_claim_does_not_run_a_claimed_project(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $staleBefore = now()->subSeconds(120);

        $this->assertTrue($project->claimDueRun(now(), $staleBefore));
        $this->assertFalse($project->claimDueRun(now(), $staleBefore));

        $this->artisan('projects:dispatch')->assertSuccessful();

        $project->refresh();

        $this->assertTrue($project->claimed_at->equalTo(Carbon::parse('2026-03-15 10:00:00', 'UTC')));
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
    }

    public function test_a_stale_claim_is_reclaimed_without_editing_the_database(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $project->claimed_at = now()->subSeconds(121);
        $project->save();

        $this->artisan('projects:dispatch')->assertSuccessful();

        $project->refresh();

        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    public function test_the_time_budget_stops_the_loop_and_leaves_remaining_projects_due(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        config(['michicast.dispatch.time_budget' => 30]);

        $first = Project::factory()->due(Carbon::parse('2026-03-15 08:00:00', 'UTC'))->create();
        $second = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();

        $runner = new FakeProjectRunner($this, advanceSeconds: 40);
        $this->app->instance(ProjectRunner::class, $runner);

        $this->artisan('projects:dispatch')->assertSuccessful();

        $first->refresh();
        $second->refresh();

        $this->assertSame([$first->getKey()], $runner->ran);
        $this->assertTrue($first->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
        $this->assertNull($first->claimed_at);
        $this->assertTrue($second->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
        $this->assertNull($second->claimed_at);
    }

    public function test_next_run_at_advances_after_a_failing_run(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        Exceptions::fake();

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();

        $this->app->instance(ProjectRunner::class, new FakeProjectRunner(
            failure: new RuntimeException('The runner failed.'),
        ));

        $this->artisan('projects:dispatch')->assertSuccessful();

        Exceptions::assertReported(RuntimeException::class);

        $project->refresh();

        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    public function test_the_scheduler_runs_dispatch_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn (Event $scheduled): bool => str_contains((string) $scheduled->command, 'projects:dispatch'),
        );

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
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
}

class FakeProjectRunner implements ProjectRunner
{
    /**
     * @var list<int>
     */
    public array $ran = [];

    public function __construct(
        private readonly ?TestCase $time = null,
        private readonly int $advanceSeconds = 0,
        private readonly ?Throwable $failure = null,
    ) {}

    public function run(Project $project, ?CarbonInterface $deadline = null): bool
    {
        $this->ran[] = (int) $project->getKey();

        if ($this->advanceSeconds > 0 && $this->time !== null) {
            $this->time->travel($this->advanceSeconds)->seconds();
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return true;
    }
}
