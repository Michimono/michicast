<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DestinationCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)->post(route('project-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'super-secret-token'],
        ]);

        $destination = $project->destinations()->sole();
        $raw = DB::table('destinations')->where('id', $destination->id)->value('credentials');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('super-secret-token', $raw);
        $this->assertSame(['token' => 'super-secret-token'], $destination->credentials);
    }

    public function test_editing_without_re_entering_a_credential_preserves_the_stored_value(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = Destination::factory()
            ->for(Project::factory()->for($organization))
            ->create(['credentials' => ['token' => 'original-secret']]);

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'alerts'],
                'credentials' => ['token' => ''],
            ])
            ->assertRedirect(route('destinations.edit', [$organization, $destination]));

        $this->assertSame(['token' => 'original-secret'], $destination->refresh()->credentials);
        $this->assertSame(['channel' => 'alerts'], $destination->config);
    }

    public function test_submitting_a_new_credential_replaces_the_stored_value(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $destination = Destination::factory()
            ->for(Project::factory()->for($organization))
            ->create(['credentials' => ['token' => 'original-secret']]);

        $this->actingAs($user)
            ->patch(route('destinations.update', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => 'replacement-secret'],
            ])
            ->assertRedirect(route('destinations.edit', [$organization, $destination]));

        $this->assertSame(['token' => 'replacement-secret'], $destination->refresh()->credentials);
    }

    public function test_the_secret_never_appears_in_a_response_or_validation_error(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $secret = 'never-echo-this-secret';

        $create = $this->actingAs($user)->post(route('project-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => $secret],
        ]);

        $destination = $project->destinations()->sole();

        $create->assertRedirect(route('project-destinations.index', [$organization, $project]));
        $create->assertDontSee($secret);

        $this->actingAs($user)
            ->get(route('project-destinations.index', [$organization, $project]))
            ->assertOk()
            ->assertDontSee($secret);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('Leave blank to keep the stored value.');

        $failed = $this->actingAs($user)->post(route('project-destinations.store', [$organization, $project]), [
            'name' => '',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => $secret],
        ]);

        $failed->assertSessionHasErrors('name');
        $this->assertStringNotContainsString($secret, (string) json_encode(session()->all()));
    }

    public function test_the_secret_is_not_written_to_the_application_log(): void
    {
        $path = storage_path('logs/destination-credential-test.log');

        if (is_file($path)) {
            unlink($path);
        }

        config([
            'logging.default' => 'destination_credential_test',
            'logging.channels.destination_credential_test' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
            ],
        ]);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $secret = 'never-log-this-secret';

        $this->actingAs($user)->post(route('project-destinations.store', [$organization, $project]), [
            'name' => 'Updates channel',
            'type' => 'recording',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => $secret],
        ])->assertRedirect();

        Log::info('Destination created for the credential test.');

        $this->assertFileExists($path);
        $this->assertStringNotContainsString($secret, (string) file_get_contents($path));

        unlink($path);
    }

    public function test_credentials_stay_out_of_array_and_json_serialization(): void
    {
        $destination = Destination::factory()->create([
            'credentials' => ['token' => 'super-secret-token'],
        ]);

        $this->assertArrayNotHasKey('credentials', $destination->toArray());
        $this->assertStringNotContainsString('super-secret-token', $destination->toJson());
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
