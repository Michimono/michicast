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
            ->assertOk()
            ->assertSee('Michimono Labs');

        $this->assertSame($organization->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_a_user_with_one_organization_sees_its_name_rather_than_a_switcher(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Michimono Labs')
            ->assertDontSee(route('current-organization.update'), false);
    }

    public function test_a_user_with_several_organizations_is_offered_a_switcher(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        Organization::factory()->withMember($user, Role::Editor)->create(['name' => 'Second Studio']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('current-organization.update'), false)
            ->assertSee('Michimono Labs')
            ->assertSee('Second Studio');
    }

    public function test_switching_changes_the_organization_for_later_requests(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        $second = Organization::factory()->withMember($user, Role::Editor)->create(['name' => 'Second Studio']);

        $response = $this->actingAs($user)->patch(route('current-organization.update'), [
            'organization' => $second->getKey(),
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success');

        $this->assertSame($second->getKey(), session(SetCurrentOrganization::SESSION_KEY));

        $this->actingAs($user)
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Second Studio');
    }

    public function test_switching_to_an_organization_the_user_does_not_belong_to_is_refused(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->ownedBy($user)->create();
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create();

        $this->actingAs($user)
            ->patch(route('current-organization.update'), ['organization' => $foreign->getKey()])
            ->assertSessionHasErrors('organization');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($own->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_an_organization_id_the_user_never_belonged_to_is_never_honoured(): void
    {
        $user = User::factory()->create();
        $own = Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);
        $foreign = Organization::factory()->ownedBy(User::factory()->create())->create(['name' => 'Not Yours']);

        $this->actingAs($user)
            ->withSession([SetCurrentOrganization::SESSION_KEY => $foreign->getKey()])
            ->get(route('members.index'))
            ->assertOk()
            ->assertSee('Michimono Labs')
            ->assertDontSee('Not Yours');

        $this->assertSame($own->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_a_new_organization_becomes_the_one_being_worked_in(): void
    {
        $user = User::factory()->create();
        Organization::factory()->ownedBy($user)->create(['name' => 'Michimono Labs']);

        $this->actingAs($user)->post(route('organizations.store'), [
            'name' => 'Second Studio',
            'timezone' => 'UTC',
        ])->assertRedirect(route('members.index'));

        $second = Organization::query()->where('name', 'Second Studio')->sole();

        $this->assertSame($second->getKey(), session(SetCurrentOrganization::SESSION_KEY));
    }

    public function test_the_switcher_stays_out_of_the_way_before_the_first_organization_exists(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertDontSee(route('current-organization.update'), false)
            ->assertDontSee(route('members.index'), false);
    }
}
