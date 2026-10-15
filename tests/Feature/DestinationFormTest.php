<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_uses_type_tiles_and_links_back_to_broadcast(): void
    {
        [$user, $organization] = $this->destinationEditor();
        $project = Project::factory()->for($organization)->create(['name' => 'Pokémon News']);

        $html = (string) $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('Back to broadcast')
            ->assertSee('Pokémon News &middot; Destination', false)
            ->assertSee('Save destination')
            ->assertSee('You can send a test message once the destination is saved.')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-destination-type-option'));
        $this->assertStringContainsString('id="destination-telegram-chat_id"', $html);
        $this->assertStringContainsString('id="destination-telegram-credentials-bot_token"', $html);
        $this->assertStringContainsString('id="destination-recording-credentials-token"', $html);
        $this->assertStringContainsString('placeholder="@mychannel"', $html);
    }

    public function test_edit_page_mirrors_source_edit_and_confirms_delete_without_inline_js(): void
    {
        [$user, $organization] = $this->destinationEditor(Role::Owner);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
            'name' => 'Log channel',
            'config' => ['channel' => 'news'],
        ]);

        $html = (string) $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Back to broadcast')
            ->assertSee('Not tested')
            ->assertSee('Activity log settings')
            ->assertSee('data-confirm="Delete this destination?', false)
            ->getContent();

        $this->assertStringNotContainsString('onsubmit=', $html);
    }

    /**
     * @return array{User, Organization}
     */
    private function destinationEditor(Role $role = Role::Editor): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
