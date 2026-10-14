<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlayDisabledTooltipTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_source_and_no_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->assertTooltip($this->overview($user, $organization, $project), 'Add a source and a destination to play this broadcast.');
    }

    public function test_no_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Destination::factory()->for($project)->create();
        Source::factory()->for($project)->create(['archived_at' => now()]);

        $this->assertTooltip($this->overview($user, $organization, $project), 'Add a source to play this broadcast.');
    }

    public function test_no_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create();

        $this->assertTooltip($this->overview($user, $organization, $project), 'Add a destination to play this broadcast.');
    }

    public function test_source_and_destination_without_a_pipeline(): void
    {
        [, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        Source::factory()->for($project)->create();
        Destination::factory()->for($project)->create();

        $this->assertSame('Connect the source to a destination to play this broadcast.', $project->activationBlockReason());
    }

    public function test_pipeline_paused_after_failures(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $this->pipeline($project, ['enabled' => false, 'failure_pause_reason' => 'Telegram rejected the token.']);

        $this->assertTooltip($this->overview($user, $organization, $project), 'Paused after repeated failures. Fix the pipeline and re-enable it to play.');
    }

    public function test_pipeline_disabled_by_the_active_broadcast_limit(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::ActiveBroadcasts,
            'limit' => 1,
            'note' => 'tooltip test',
        ]);
        $this->pipeline(Project::factory()->for($organization)->create(), ['enabled' => true]);
        $project = Project::factory()->for($organization)->create();
        $this->pipeline($project, ['enabled' => false]);

        $this->assertTooltip($this->overview($user, $organization, $project), 'Active broadcast limit reached. Pause or archive another broadcast to play this one.');
    }

    public function test_pipeline_disabled(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $this->pipeline($project, ['enabled' => false]);

        $this->assertTooltip($this->overview($user, $organization, $project), 'Enable the pipeline to play this broadcast.');
    }

    public function test_an_enabled_play_has_no_tooltip(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $this->pipeline($project, ['enabled' => true]);

        $this->overview($user, $organization, $project)
            ->assertSee(route('active-broadcasts.store', [$organization, $project]), false)
            ->assertDontSee('data-tooltip', false)
            ->assertDontSee('role="tooltip"', false)
            ->assertDontSee('aria-disabled', false);
    }

    private function assertTooltip(TestResponse $response, string $message): void
    {
        $html = $response->assertOk()->getContent();

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $wrapper = $xpath->query('//span[@data-tooltip][@tabindex="0"][@aria-describedby="play-disabled-reason"]');
        $this->assertSame(1, $wrapper->length, 'The disabled Play needs one focusable tooltip wrapper.');

        $button = $xpath->query('.//button[@disabled][@aria-disabled="true"][contains(normalize-space(.), "Play")]', $wrapper->item(0));
        $this->assertSame(1, $button->length);

        $tip = $xpath->query('//*[@id="play-disabled-reason"][@role="tooltip"]');
        $this->assertSame(1, $tip->length);
        $this->assertSame($message, trim($tip->item(0)->textContent));
        $this->assertStringNotContainsString('style=', $wrapper->item(0)->ownerDocument->saveHTML($wrapper->item(0)));
    }

    private function overview(User $user, Organization $organization, Project $project): TestResponse
    {
        return $this->actingAs($user)->get(route('broadcasts.show', [$organization, $project]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pipeline(Project $project, array $attributes): Broadcast
    {
        return Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => Source::factory()->for($project)->create()->getKey(),
            'destination_id' => Destination::factory()->for($project)->create()->getKey(),
            ...$attributes,
        ]);
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
