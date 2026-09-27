<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_editor_can_delete_a_source_and_free_the_pipeline_slot(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['name' => 'Feed']);
        $destination = Destination::factory()->for($project)->create();
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->delete(route('sources.destroy', [$organization, $source]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('success', 'The source has been deleted.');

        $this->assertDatabaseMissing('sources', ['id' => $source->getKey()]);
        $this->assertSame(0, $project->broadcasts()->count());
        $this->assertSame(0, $project->sources()->count());

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Add a source')
            ->assertDontSee('Feed')
            ->getContent();

        $this->assertStringContainsString(route('broadcast-sources.create', [$organization, $project]), $html);
    }

    public function test_source_edit_offers_delete_not_archive_or_disable(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $source = $this->sourceIn($organization);

        $this->actingAs($user)
            ->get(route('sources.edit', [$organization, $source]))
            ->assertOk()
            ->assertSee('Delete source')
            ->assertDontSee('>Archive</button>', false)
            ->assertDontSee('>Disable</button>', false)
            ->assertDontSee('>Enable</button>', false)
            ->assertDontSee('>Restore</button>', false)
            ->assertDontSee('Enabled')
            ->assertDontSee('Disabled')
            ->assertDontSee('name="enabled"', false);
    }

    public function test_source_list_routes_are_gone(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get('/organizations/'.$organization->getKey().'/broadcasts/'.$project->getKey().'/sources')
            ->assertMethodNotAllowed();
    }

    public function test_only_unarchived_sources_are_left_for_later_processing(): void
    {
        $project = Project::factory()->create();
        $runnable = Source::factory()->for($project)->create();
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

    public function test_a_viewer_cannot_delete_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $source = $this->sourceIn($organization);

        $this->actingAs($user)
            ->delete(route('sources.destroy', [$organization, $source]))
            ->assertForbidden();

        $this->assertDatabaseHas('sources', ['id' => $source->getKey()]);
    }

    public function test_a_member_of_another_organization_cannot_delete_a_source_here(): void
    {
        [$outsider] = $this->memberHolding(Role::Owner);
        [, $organization] = $this->memberHolding(Role::Owner);
        $source = $this->sourceIn($organization);

        $this->actingAs($outsider)
            ->delete(route('sources.destroy', [$organization, $source]))
            ->assertNotFound();

        $this->assertDatabaseHas('sources', ['id' => $source->getKey()]);
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
