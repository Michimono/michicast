<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\Mapping\DefaultMappings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastMappingFormTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function overview_shows_inline_mapping_between_source_and_destination(): void
    {
        [$user, $organization, $project] = $this->broadcastWorkspace(Role::Editor);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Mapping', false)
            ->assertSee('Build the destination data step by step.', false)
            ->assertSee('Add step', false)
            ->assertDontSee('Step changes save automatically.', false)
            ->assertDontSee('Save now', false)
            ->assertSee('Nothing will be sent.', false)
            ->assertSee('Source', false)
            ->assertSee('Destination', false);
    }

    #[Test]
    public function telegram_editor_payload_includes_parse_mode(): void
    {
        [$user, $organization, $project, $broadcast] = $this->broadcastWorkspace(Role::Editor);

        $broadcast->destination->update([
            'type' => 'telegram',
            'config' => [
                'chat_id' => '@pocketnews',
                'parse_mode' => 'MarkdownV2',
            ],
            'credentials' => [
                'bot_token' => '123:ABC',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('MarkdownV2', false)
            ->assertSee('parse_mode', false);
    }

    #[Test]
    public function an_editor_can_save_mapping_steps(): void
    {
        [$user, $organization, $project, $broadcast] = $this->broadcastWorkspace(Role::Editor);

        $mapping = (new DefaultMappings)->for('fake', 'recording')?->toArray();

        $this->actingAs($user)
            ->patchJson(route('broadcast-mappings.update', [$organization, $broadcast]), [
                'mapping' => $mapping,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($broadcast->refresh()->mapping);
        $this->assertSame('set_field', $broadcast->mapping['steps'][0]['operation']);
    }

    #[Test]
    public function preview_endpoint_evaluates_without_saving_or_publishing(): void
    {
        [$user, $organization, $project, $broadcast] = $this->broadcastWorkspace(Role::Editor);

        $mapping = (new DefaultMappings)->for('fake', 'recording')?->toArray();

        $this->actingAs($user)
            ->postJson(route('broadcast-mapping-previews.store', [$organization, $broadcast]), [
                'mapping' => $mapping,
            ])
            ->assertOk()
            ->assertJsonStructure(['data', 'human' => ['text'], 'errors']);

        $this->assertNull($broadcast->refresh()->mapping);
    }

    #[Test]
    public function saved_mapping_is_used_during_a_dry_run(): void
    {
        [$user, $organization, $project, $broadcast] = $this->broadcastWorkspace(Role::Editor);

        $broadcast->update([
            'mapping' => (new DefaultMappings)->for('fake', 'recording')?->toArray(),
        ]);

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertRedirect();

        $this->assertDatabaseHas('project_runs', [
            'project_id' => $project->getKey(),
            'is_dry' => 1,
        ]);
    }

    /**
     * @return array{0: User, 1: Organization, 2: Project, 3: Broadcast}
     */
    private function broadcastWorkspace(Role $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create(['type' => 'fake', 'name' => 'Pocket feed']);
        $destination = Destination::factory()->for($project)->create(['type' => 'recording', 'name' => 'Recorder']);
        $broadcast = $project->ensureDefaultBroadcast();
        $this->assertNotNull($broadcast);
        $broadcast->update([
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        return [$user, $organization, $project, $broadcast->fresh(['source', 'destination'])];
    }
}
