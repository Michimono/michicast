<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationPacingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_destination_forms_show_the_send_pace(): void
    {
        [$user, $organization] = $this->editor();
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => Project::factory()->for($organization)->create()->getKey(),
            'rate_limit_count' => 2,
            'rate_limit_minutes' => 10,
            'max_queue_size' => 25,
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('Send pace')
            ->assertSee('Messages')
            ->assertSee('Minutes')
            ->assertSee('Max waiting')
            ->assertSee('destination queue is full');

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Send pace')
            ->assertSee('value="2"', false)
            ->assertSee('value="10"', false)
            ->assertSee('value="25"', false);
    }

    public function test_an_editor_can_save_and_clear_a_send_pace(): void
    {
        [$user, $organization] = $this->editor();
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->post(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Updates channel',
                'type' => 'recording',
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => 'super-secret-token'],
                'rate_limit_count' => 4,
                'rate_limit_minutes' => 15,
                'max_queue_size' => 8,
            ])
            ->assertRedirect();

        $destination = $project->destinations()->sole();

        $this->assertSame(4, $destination->rate_limit_count);
        $this->assertSame(15, $destination->rate_limit_minutes);
        $this->assertSame(8, $destination->max_queue_size);

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => 'Updates channel',
                'config' => ['channel' => 'alerts'],
                'credentials' => ['token' => ''],
                'rate_limit_count' => '',
                'rate_limit_minutes' => '',
                'max_queue_size' => '',
            ])
            ->assertRedirect(route('destinations.edit', [$organization, $destination]));

        $destination->refresh();

        $this->assertNull($destination->rate_limit_count);
        $this->assertNull($destination->rate_limit_minutes);
        $this->assertNull($destination->max_queue_size);
        $this->assertSame(['channel' => 'alerts'], $destination->config);
    }

    public function test_a_rate_needs_both_a_count_and_a_window(): void
    {
        [$user, $organization] = $this->editor();
        $destination = Destination::factory()->create([
            'project_id' => Project::factory()->for($organization)->create()->getKey(),
        ]);

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
                'rate_limit_count' => 3,
                'rate_limit_minutes' => '',
                'max_queue_size' => '',
            ])
            ->assertSessionHasErrors([
                'rate_limit_minutes' => 'Enter the minutes as well as how many messages, or leave both empty.',
            ]);

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
                'rate_limit_count' => 0,
                'rate_limit_minutes' => 10,
                'max_queue_size' => '',
            ])
            ->assertSessionHasErrors([
                'rate_limit_count' => 'Messages must be at least 1.',
            ]);

        $destination->refresh();

        $this->assertNull($destination->rate_limit_count);
        $this->assertNull($destination->rate_limit_minutes);
    }

    public function test_saving_from_the_pipeline_keeps_a_pace_it_does_not_edit(): void
    {
        [$user, $organization] = $this->editor();
        $destination = Destination::factory()->create([
            'project_id' => Project::factory()->for($organization)->create()->getKey(),
            'rate_limit_count' => 2,
            'rate_limit_minutes' => 10,
            'max_queue_size' => 4,
        ]);

        $this->actingAs($user)
            ->patchJson(route('destinations.update', [$organization, $destination]), [
                'name' => 'Alerts channel',
                'config' => ['channel' => 'alerts'],
                'credentials' => ['token' => ''],
            ])
            ->assertOk();

        $destination->refresh();

        $this->assertSame('Alerts channel', $destination->name);
        $this->assertSame(2, $destination->rate_limit_count);
        $this->assertSame(10, $destination->rate_limit_minutes);
        $this->assertSame(4, $destination->max_queue_size);
    }

    /**
     * @return array{User, Organization}
     */
    private function editor(): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, Role::Editor)->create()];
    }
}
