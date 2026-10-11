<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_who_belongs_to_no_organization_is_sent_to_the_creation_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.create'));
    }

    public function test_a_request_resolves_the_only_organization_the_user_belongs_to(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', $organization));

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Michimono Labs');

        $this->assertSame($organization->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_visiting_another_organization_is_how_the_user_switches(): void
    {
        $user = User::factory()->create();
        $first = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        $second = Organization::factory()->withMember($user, Role::Editor)->create(['name' => 'Second Studio']);

        $this->actingAs($user)
            ->get(route('organizations.show', $first))
            ->assertOk()
            ->assertSee('Michimono Labs');

        $this->actingAs($user)
            ->get(route('organizations.show', $second))
            ->assertOk()
            ->assertSee('Second Studio')
            ->assertSee('Michimono Labs')
            ->assertSee('All organizations');

        $this->assertSame($second->getKey(), session(SetCurrentOrganization::SESSION_KEY));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', $second));
    }

    public function test_an_organization_the_user_does_not_belong_to_is_not_found(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create();
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($user)
            ->get(route('organizations.show', $foreign))
            ->assertNotFound();
    }

    public function test_an_organization_id_the_user_never_belonged_to_is_never_honoured(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create(['name' => 'Not Yours']);

        $this->actingAs($user)
            ->withSession([SetCurrentOrganization::SESSION_KEY => $foreign->getKey()])
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', $own));
    }

    public function test_a_new_organization_becomes_the_one_being_worked_in(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $response = $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Second Studio',
            'timezone' => 'UTC',
        ]);

        $second = Organization::query()->where('name', 'Second Studio')->sole();

        $response->assertRedirect(route('organizations.show', $second));

        $this->assertSame($second->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }
}
