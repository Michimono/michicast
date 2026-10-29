<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Jobs\RunProject;
use App\Models\Broadcast;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\RunClaimedProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_now_on_a_source_only_runs_that_source(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project, $first, $second] = $this->projectWithTwoSources();

        $this->actingAs($user)
            ->postJson(route('source-runs.store', [$organization, $first]))
            ->assertOk()
            ->assertJsonPath('queued', false)
            ->assertJsonPath('source.id', $first->getKey())
            ->assertJsonPath('run.status', RunStatus::Completed->value);

        $run = ProjectRun::query()->where('project_id', $project->getKey())->where('is_dry', false)->sole();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame(1, ContentItem::query()->where('source_id', $first->getKey())->count());
        $this->assertSame(0, ContentItem::query()->where('source_id', $second->getKey())->count());
    }

    public function test_run_now_on_a_source_is_rejected_for_a_paused_broadcast(): void
    {
        [$user, $organization, $project, $first] = $this->projectWithTwoSources();
        $project->forceFill(['status' => ProjectStatus::Paused])->save();

        $this->actingAs($user)
            ->postJson(route('source-runs.store', [$organization, $first]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only an active broadcast can run now.');

        $this->assertSame(0, ProjectRun::query()->where('project_id', $project->getKey())->count());
    }

    public function test_run_now_on_a_source_without_a_live_route_is_rejected(): void
    {
        [$user, $organization, $project] = $this->projectWithTwoSources();
        $loose = $this->source($project, 'loose-1');

        $this->actingAs($user)
            ->postJson(route('source-runs.store', [$organization, $loose]))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This source is not on a live route, so there is nothing to run.');

        $this->assertSame(0, ProjectRun::query()->where('project_id', $project->getKey())->count());
    }

    public function test_a_viewer_cannot_run_a_source(): void
    {
        [, $organization, , $first] = $this->projectWithTwoSources();
        $viewer = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => Role::Viewer,
        ]);

        $this->actingAs($viewer)
            ->postJson(route('source-runs.store', [$organization, $first]))
            ->assertForbidden();
    }

    public function test_run_now_on_a_source_queues_a_run_for_just_that_source(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));
        config(['michicast.runs_on_queue' => true]);
        Queue::fake();

        [$user, $organization, $project, $first] = $this->projectWithTwoSources();

        $this->actingAs($user)
            ->postJson(route('source-runs.store', [$organization, $first]))
            ->assertOk()
            ->assertJsonPath('queued', true);

        Queue::assertPushed(RunProject::class, fn (RunProject $job): bool => $job->projectId === (int) $project->getKey()
            && $job->sourceIds === [(int) $first->getKey()]);
        $this->assertNotNull($project->refresh()->claimed_at);
    }

    public function test_a_claimed_source_run_reads_only_that_source_and_keeps_its_schedule(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [, , $project, $first, $second] = $this->projectWithTwoSources();
        $later = Carbon::parse('2026-03-15 13:00:00', 'UTC');
        $first->forceFill(['next_run_at' => $later])->save();

        $claimedAt = Carbon::parse('2026-03-15 10:00:00', 'UTC');
        $project->forceFill(['next_run_at' => $claimedAt, 'claimed_at' => $claimedAt])->save();

        (new RunProject((int) $project->getKey(), $claimedAt->toIso8601String(), [(int) $first->getKey()]))
            ->handle($this->app->make(RunClaimedProject::class));

        $this->assertSame(1, ContentItem::query()->where('source_id', $first->getKey())->count());
        $this->assertSame(0, ContentItem::query()->where('source_id', $second->getKey())->count());
        $this->assertNull($project->refresh()->claimed_at);
        $this->assertTrue($first->refresh()->next_run_at->equalTo($later));
    }

    /**
     * @return array{User, Organization, Project, Source, Source}
     */
    private function projectWithTwoSources(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->state(['status' => ProjectStatus::Active])->create();
        $first = $this->source($project, 'first-1');
        $second = $this->source($project, 'second-1');
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);

        foreach ([$first, $second] as $source) {
            Broadcast::factory()->create([
                'project_id' => $project->getKey(),
                'source_id' => $source->getKey(),
                'destination_id' => $destination->getKey(),
                'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
            ]);
        }

        return [$user, $organization, $project, $first, $second];
    }

    private function source(Project $project, string $externalId): Source
    {
        return Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/'.$externalId.'.xml',
                'items' => [
                    [
                        'external_id' => $externalId,
                        'title' => 'Item '.$externalId,
                        'body' => 'A keepable item.',
                        'canonical_url' => 'https://example.com/posts/'.$externalId,
                        'published_at' => '2026-03-15 09:00:00',
                    ],
                ],
            ],
        ]);
    }
}
