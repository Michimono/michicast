<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $destination = Destination::factory()->create();
        $project = $destination->project;
        $organization = $project->organization;

        $this->get(route('broadcasts.show', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->get(route('broadcast-destinations.create', [$organization, $project]))->assertRedirect(route('session.create'));
        $this->post(route('broadcast-destinations.store', [$organization, $project]), [])->assertRedirect(route('session.create'));
        $this->get(route('destinations.edit', [$organization, $destination]))->assertRedirect(route('session.create'));
        $this->patch(route('destinations.update', [$organization, $destination]), [])->assertRedirect(route('session.create'));
    }

    public function test_the_index_lists_the_destinations_of_this_project_only(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        Destination::factory()->for($project)->create(['name' => 'Updates channel']);
        Destination::factory()->for(Project::factory()->for($organization))->create(['name' => 'Another project channel']);
        Destination::factory()->create(['name' => 'Someone else channel']);

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Updates channel')
            ->assertDontSee('Another project channel')
            ->assertDontSee('Someone else channel')
            ->assertDontSee('New destination');
    }

    public function test_the_create_screen_renders_the_fields_the_chosen_type_declares(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->assertOk()
            ->assertSee('Activity log (testing)')
            ->assertSee('Channel')
            ->assertSee('Token')
            ->assertSee('config[channel]', false)
            ->assertSee('credentials[token]', false);
    }

    public function test_an_editor_can_add_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $response = $this->actingAs($user)->post(route('broadcast-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'super-secret-token'],
        ]);

        $destination = $project->destinations()->sole();

        $response->assertRedirect(route('broadcasts.show', [$organization, $project]));
        $response->assertSessionHas('success', 'The destination has been added.');

        $this->assertSame('Updates channel', $destination->name);
        $this->assertSame('recording', $destination->type);
        $this->assertSame(['channel' => 'updates'], $destination->config);
        $this->assertSame(['token' => 'super-secret-token'], $destination->credentials);
        $this->assertTrue($destination->isRunnable());
    }

    public function test_configuration_or_credentials_that_fail_the_provider_rules_are_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Updates channel',
                'type' => 'recording',
                'config' => ['channel' => ''],
                'credentials' => ['token' => ''],
            ])
            ->assertSessionHasErrors(['config.channel', 'credentials.token']);

        $this->assertSame(0, $project->destinations()->count());
    }

    public function test_settings_the_type_does_not_declare_are_not_stored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)->post(route('broadcast-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => [
                'channel' => 'updates',
                'admin' => 'true',
            ],
            'credentials' => [
                'token' => 'super-secret-token',
                'extra' => 'nope',
            ],
        ]);

        $destination = $project->destinations()->sole();

        $this->assertSame(['channel' => 'updates'], $destination->config);
        $this->assertSame(['token' => 'super-secret-token'], $destination->credentials);
    }

    public function test_an_editor_can_change_a_name_and_configuration(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = $this->destinationIn($organization, ['name' => 'Updates channel']);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Updates channel')
            ->assertSee('updates')
            ->assertDontSee('factory-secret-token');

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => 'Alerts channel',
                'config' => ['channel' => 'alerts'],
                'credentials' => ['token' => ''],
            ])
            ->assertRedirect(route('destinations.edit', [$organization, $destination]))
            ->assertSessionHas('success', 'The destination has been updated.');

        $destination->refresh();

        $this->assertSame('Alerts channel', $destination->name);
        $this->assertSame(['channel' => 'alerts'], $destination->config);
        $this->assertSame(['token' => 'factory-secret-token'], $destination->credentials);
    }

    public function test_a_viewer_cannot_add_or_change_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $destination = $this->destinationIn($organization);
        $project = $destination->project;

        $this->actingAs($user)->get(route('broadcast-destinations.create', [$organization, $project]))->assertForbidden();
        $this->actingAs($user)->post(route('broadcast-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'super-secret-token'],
        ])->assertForbidden();
        $this->actingAs($user)->get(route('destinations.edit', [$organization, $destination]))->assertForbidden();
        $this->actingAs($user)->patch(route('destinations.update', [$organization, $destination]), [
            'name' => 'Renamed',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => ''],
        ])->assertForbidden();
    }

    public function test_a_destination_of_another_organization_is_out_of_reach(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $elsewhere = Destination::factory()->create(['name' => 'Someone else channel']);

        $this->actingAs($user)->get(route('destinations.edit', [$organization, $elsewhere]))->assertNotFound();
        $this->actingAs($user)->patch(route('destinations.update', [$organization, $elsewhere]), [
            'name' => 'Taken over',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => ''],
        ])->assertNotFound();

        $this->assertSame('Someone else channel', $elsewhere->refresh()->name);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function destinationIn(Organization $organization, array $attributes = []): Destination
    {
        return Destination::factory()
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
