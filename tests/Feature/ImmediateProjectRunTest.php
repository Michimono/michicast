<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ImmediateProjectRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_now_executes_synchronously_and_stays_on_overview(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->projectReadyToRun();
        $this->recordingDestination();

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success');

        $run = ProjectRun::query()->where('project_id', $project->getKey())->where('is_dry', false)->sole();
        $this->assertSame(RunStatus::Completed, $run->status);
    }

    public function test_run_now_is_rejected_for_a_draft_broadcast(): void
    {
        [$user, $organization, $project] = $this->projectReadyToRun(draft: true);

        $this->assertSame(ProjectStatus::Draft, $project->status);

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error', 'Only an active broadcast can run now.');

        $this->assertSame(0, ProjectRun::query()->where('project_id', $project->getKey())->count());
        $this->assertSame(ProjectStatus::Draft, $project->refresh()->status);
    }

    public function test_run_now_is_rejected_for_a_paused_broadcast(): void
    {
        [$user, $organization, $project] = $this->projectReadyToRun();
        $project->forceFill(['status' => ProjectStatus::Paused])->save();

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error', 'Only an active broadcast can run now.');
    }

    public function test_a_viewer_cannot_run_now_immediately(): void
    {
        [, $organization, $project] = $this->projectReadyToRun();
        $viewer = User::factory()->create();
        OrganizationMember::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $viewer->getKey(),
            'role' => Role::Viewer,
        ]);

        $this->actingAs($viewer)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertForbidden();
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function projectReadyToRun(bool $draft = false): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $project = Project::factory()->for($organization)->state([
            'status' => $draft ? ProjectStatus::Draft : ProjectStatus::Active,
        ])->create();
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

    private function recordingDestination(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
