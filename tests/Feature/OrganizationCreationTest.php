<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('organizations.create'))->assertRedirect(route('session.create'));

        $this->post(route('organizations.store'), [
            'name' => 'Michimono Labs',
            'timezone' => 'UTC',
        ])->assertRedirect(route('session.create'));

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_a_user_without_an_organization_is_sent_to_the_creation_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_a_user_who_owns_an_organization_reaches_the_dashboard(): void
    {
        $user = User::factory()->create();

        Organization::factory()->ownedBy($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', Organization::query()->sole()));
    }

    public function test_a_user_without_an_organization_can_still_open_the_creation_screen_and_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('organizations.create'))->assertOk();

        $this->actingAs($user)->delete(route('session.destroy'))->assertRedirect(route('home'));
    }

    public function test_the_creation_screen_offers_the_timezone_the_user_already_reads_times_in(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Madrid']);

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertSee('<option value="Europe/Madrid" selected>', false);
    }

    public function test_a_user_can_create_an_organization_and_is_recorded_as_its_owner(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Madrid']);

        $response = $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Michimono Labs',
            'timezone' => 'Europe/Madrid',
        ]);

        $organization = Organization::query()->sole();

        $response->assertRedirect(route('members.index', $organization));
        $response->assertSessionHas('success');

        $this->assertSame('Michimono Labs', $organization->name);
        $this->assertSame('michimono-labs', $organization->slug);
        $this->assertSame('Europe/Madrid', $organization->timezone);
        $this->assertNull($organization->plan);
        $this->assertSame(Role::Owner, $organization->roleFor($user));
    }

    public function test_a_second_organization_named_the_same_gets_its_own_slug(): void
    {
        $user = User::factory()->create();

        foreach (['Michimono Labs', 'Michimono Labs', 'Michimono Labs'] as $name) {
            $this->actingAs($user)
                ->post(route('organizations.store'), ['name' => $name, 'timezone' => 'UTC'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            ['michimono-labs', 'michimono-labs-2', 'michimono-labs-3'],
            Organization::query()->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_a_name_without_sluggable_characters_still_gets_a_url_safe_slug(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => '!!!', 'timezone' => 'UTC'])
            ->assertSessionHasNoErrors();

        $this->assertSame('organization', Organization::query()->sole()->slug);
    }

    public function test_a_long_name_is_shortened_to_fit_the_slug_column(): void
    {
        $user = User::factory()->create();
        $name = trim(str_repeat('Michimono ', 25));

        foreach ([$name, $name] as $attempt) {
            $this->actingAs($user)
                ->post(route('organizations.store'), ['name' => $attempt, 'timezone' => 'UTC'])
                ->assertSessionHasNoErrors();
        }

        $slugs = Organization::query()->orderBy('id')->pluck('slug')->all();

        $this->assertLessThanOrEqual(240, strlen($slugs[0]));
        $this->assertStringStartsWith('michimono-michimono', $slugs[0]);
        $this->assertStringEndsNotWith('-', $slugs[0]);
        $this->assertSame($slugs[0].'-2', $slugs[1]);
    }

    public function test_the_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => '', 'timezone' => 'UTC'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_an_unknown_timezone_identifier_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), [
                'name' => 'Michimono Labs',
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertDatabaseCount('organizations', 0);
    }
}
