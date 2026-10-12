<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Recording destination is a test helper (#202): only offered and accepted
 * for new destinations in local and testing, while existing ones keep working.
 */
class TestDestinationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_flag_defaults_on_in_the_testing_environment(): void
    {
        $this->assertTrue(config('michicast.test_destinations_enabled'));
        $this->assertContains('recording', app(DestinationProviderRegistry::class)->selectableTypes());
    }

    public function test_recording_is_hidden_from_the_create_form_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $registry = app(DestinationProviderRegistry::class);
        $this->assertSame(['telegram'], $registry->selectableTypes());
        $this->assertSame('telegram', $registry->defaultType());
        $this->assertTrue($registry->has('recording'));

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('value="telegram"', false)
            ->assertDontSee('value="recording"', false)
            ->assertDontSee('Recording destination');

        // Asking for it explicitly falls back to the default selectable type.
        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->assertOk()
            ->assertDontSee('Recording destination');
    }

    public function test_recording_is_offered_in_local(): void
    {
        config(['michicast.test_destinations_enabled' => true]);
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->assertOk()
            ->assertSee('Recording destination');
    }

    public function test_store_rejects_recording_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->postJson(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Telegram test channel',
                'type' => 'recording',
                'config' => ['channel' => 'news'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, $project->destinations()->count());
    }

    public function test_an_existing_recording_destination_still_renders_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
            'name' => 'Telegram test channel',
            'config' => ['channel' => 'news'],
        ]);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Telegram test channel');
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }

    private function productionLike(): void
    {
        // The flag is what production resolves to (APP_ENV is read when config loads).
        // Switching the app env itself would also turn on CSRF checks in tests.
        config(['michicast.test_destinations_enabled' => false]);
    }
}
