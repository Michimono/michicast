<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\InvalidSourceTransitionException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_disable_and_enable_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-sources.destroy', [$organization, $source]))
            ->assertSessionHas('success', 'The source has been disabled.');

        $this->assertFalse($source->refresh()->enabled);
        $this->assertFalse($source->isRunnable());

        $this->actingAs($user)
            ->post(route('enabled-sources.store', [$organization, $source]))
            ->assertSessionHas('success', 'The source has been enabled.');

        $this->assertTrue($source->refresh()->enabled);
        $this->assertTrue($source->isRunnable());
    }

    public function test_an_editor_can_archive_and_restore_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization);
        $project = $source->project;

        $this->actingAs($user)
            ->post(route('archived-sources.store', [$organization, $source]))
            ->assertRedirect(route('project-sources.index', [$organization, $project]))
            ->assertSessionHas('success', 'The source has been archived.');

        $this->assertTrue($source->refresh()->isArchived());
        $this->assertFalse($source->isRunnable());

        $this->actingAs($user)
            ->delete(route('archived-sources.destroy', [$organization, $source]))
            ->assertRedirect(route('project-sources.index', [$organization, $project]))
            ->assertSessionHas('success', 'The source has been restored.');

        $this->assertFalse($source->refresh()->isArchived());
        $this->assertTrue($source->isRunnable());
    }

    public function test_an_archived_source_cannot_be_enabled_until_it_is_restored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization, ['enabled' => false, 'archived_at' => now()]);

        $this->actingAs($user)
            ->post(route('enabled-sources.store', [$organization, $source]))
            ->assertSessionHas('error', 'An archived source cannot be enabled until it is restored.');

        $this->assertFalse($source->refresh()->enabled);

        $this->expectException(InvalidSourceTransitionException::class);

        $source->enable();
    }

    public function test_the_index_separates_archived_sources_from_the_rest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization, ['name' => 'Live feed']);
        Source::factory()->for($source->project)->archived()->create(['name' => 'Retired feed']);

        $this->actingAs($user)
            ->get(route('project-sources.index', [$organization, $source->project]))
            ->assertOk()
            ->assertSee('Live feed')
            ->assertSee('Retired feed')
            ->assertSee('Archived sources')
            ->assertSee('Restore');
    }

    public function test_only_enabled_and_unarchived_sources_are_left_for_later_processing(): void
    {
        $project = Project::factory()->create();
        $runnable = Source::factory()->for($project)->create();
        Source::factory()->for($project)->disabled()->create();
        Source::factory()->for($project)->archived()->create();

        $this->assertSame(
            [$runnable->id],
            $project->sources()->runnable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_a_source_describes_itself_to_its_provider(): void
    {
        $source = Source::factory()->create(['config' => ['feed_url' => 'https://example.com/feed.xml']]);

        $definition = $source->toDefinition();

        $this->assertSame('fake', $definition->type);
        $this->assertSame($source->project_id, $definition->projectId);
        $this->assertSame($source->id, $definition->sourceId);
        $this->assertSame('https://example.com/feed.xml', $definition->config('feed_url'));
    }

    public function test_a_viewer_cannot_change_a_source_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $source = $this->sourceIn($organization);

        $this->actingAs($user)
            ->delete(route('enabled-sources.destroy', [$organization, $source]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('archived-sources.store', [$organization, $source]))
            ->assertForbidden();

        $this->assertTrue($source->refresh()->isRunnable());
    }

    public function test_a_member_of_another_organization_cannot_change_a_source_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [, $organization] = $this->memberHolding(Role::Owner);
        $source = $this->sourceIn($organization);

        $this->actingAs($outsider)
            ->post(route('archived-sources.store', [$organization, $source]))
            ->assertNotFound();

        $this->assertFalse($source->refresh()->isArchived());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sourceIn(Organization $organization, array $attributes = []): Source
    {
        return Source::factory()
            ->for(Project::factory()->for($organization))
            ->create($attributes);
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
