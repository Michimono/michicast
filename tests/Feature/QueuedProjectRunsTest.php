<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\RunProject;
use App\Jobs\SendLatestJob;
use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\ProjectRunner;
use App\Support\RunClaimedProject;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class QueuedProjectRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['michicast.runs_on_queue' => true]);
    }

    public function test_dispatch_pushes_one_job_per_due_project(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        Queue::fake();

        $dueA = Project::factory()->due(Carbon::parse('2026-03-15 08:00:00', 'UTC'))->create();
        $dueB = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $notDue = Project::factory()->due(Carbon::parse('2026-03-15 11:00:00', 'UTC'))->create();

        $this->artisan('projects:dispatch')->assertSuccessful();

        Queue::assertPushed(RunProject::class, 2);
        Queue::assertPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $dueA->getKey());
        Queue::assertPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $dueB->getKey());
        Queue::assertNotPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $notDue->getKey());

        $dueA->refresh();
        $dueB->refresh();
        $notDue->refresh();

        $this->assertNotNull($dueA->claimed_at);
        $this->assertNotNull($dueB->claimed_at);
        $this->assertNull($notDue->claimed_at);
        $this->assertTrue($dueA->next_run_at->equalTo(Carbon::parse('2026-03-15 08:00:00', 'UTC')));
        $this->assertTrue($dueB->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
    }

    public function test_run_project_job_releases_the_claim_and_advances_next_run_at(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $claimedAt = Carbon::parse('2026-03-15 10:00:00', 'UTC');
        $project->forceFill(['claimed_at' => $claimedAt])->save();

        $this->app->instance(ProjectRunner::class, new class implements ProjectRunner
        {
            public function run(Project $project, ?CarbonInterface $deadline = null): bool
            {
                return true;
            }
        });

        (new RunProject((int) $project->getKey(), $claimedAt->toIso8601String()))
            ->handle($this->app->make(RunClaimedProject::class));

        $project->refresh();

        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    public function test_a_stale_claim_is_re_dispatched(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        Queue::fake();

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();
        $project->forceFill([
            'claimed_at' => now()->subSeconds(121),
        ])->save();

        $this->artisan('projects:dispatch')->assertSuccessful();

        Queue::assertPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $project->getKey());

        $project->refresh();

        $this->assertNotNull($project->claimed_at);
        $this->assertTrue($project->claimed_at->equalTo(Carbon::parse('2026-03-15 10:00:00', 'UTC')));
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-15 09:00:00', 'UTC')));
    }

    public function test_run_now_queues_a_job_and_makes_no_http_call(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        Queue::fake();
        Http::preventStrayRequests();

        [$user, $organization, $project] = $this->projectReadyToRun();

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'Queued');

        Queue::assertPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $project->getKey());

        $project->refresh();

        $this->assertNotNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->lte(now()));
    }

    public function test_a_failing_inline_run_now_flash_is_redacted(): void
    {
        config(['michicast.runs_on_queue' => false]);

        [$user, $organization, $project] = $this->projectReadyToRun();

        $this->app->instance(ProjectRunner::class, new class implements ProjectRunner
        {
            public function run(Project $project, ?CarbonInterface $deadline = null): bool
            {
                throw new RuntimeException('Provider rejected token 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
            }
        });

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'The run could not finish:')
                    && str_contains($message, '[redacted]')
                    && ! str_contains($message, '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
            });
    }

    public function test_send_latest_queues_a_job_when_runs_on_queue(): void
    {
        Queue::fake();

        [$user, $organization, $project] = $this->projectReadyToRun();
        Source::factory()->create(['project_id' => $project->getKey()]);
        // Content item required so the controller queues rather than rejecting.
        ContentItem::factory()->for($project)->create();

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('success', 'Queued');

        Queue::assertPushed(SendLatestJob::class, fn (SendLatestJob $job): bool => $job->projectId === (int) $project->getKey());
    }

    public function test_with_the_switch_off_dispatch_still_runs_inline(): void
    {
        config(['michicast.runs_on_queue' => false]);

        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        Queue::fake();

        $project = Project::factory()->due(Carbon::parse('2026-03-15 09:00:00', 'UTC'))->create();

        $this->artisan('projects:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();

        $project->refresh();

        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function projectReadyToRun(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    [
                        'external_id' => 'keep-1',
                        'title' => 'Pokemon drop',
                        'body' => 'A keepable item.',
                        'canonical_url' => 'https://example.com/posts/keep-1',
                        'published_at' => '2026-03-15 09:00:00',
                    ],
                ],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        return [$user, $organization, $project];
    }
}
