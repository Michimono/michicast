<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $organization = Organization::factory()->create();

        $this->get(route('organization-settings.edit', $organization))->assertRedirect(route('session.create'));
        $this->patch(route('organization-settings.update', $organization), [
            'name' => 'Michimono Labs',
            'timezone' => 'UTC',
        ])->assertRedirect(route('session.create'));
    }

    public function test_the_settings_screen_shows_the_current_values_and_says_the_timezone_drives_scheduling(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, [
            'name' => 'Michimono Labs',
            'timezone' => 'Europe/Madrid',
        ]);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertSee('Michimono Labs')
            ->assertSee('<option value="Europe/Madrid" selected>', false)
            ->assertSee('This timezone drives scheduling. Your profile timezone only changes how times appear on screen.');
    }

    #[DataProvider('administrativeRoles')]
    public function test_an_administrator_can_update_the_name_and_timezone(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role, [
            'name' => 'Michimono Labs',
            'slug' => 'michimono-labs',
            'timezone' => 'UTC',
        ]);

        $response = $this->actingAs($user)->patch(route('organization-settings.update', $organization), [
            'name' => 'Second Studio',
            'timezone' => 'Asia/Tokyo',
        ]);

        $response->assertRedirect(route('organization-settings.edit', $organization));
        $response->assertSessionHas('success');

        $organization->refresh();

        $this->assertSame('Second Studio', $organization->name);
        $this->assertSame('Asia/Tokyo', $organization->timezone);
        $this->assertSame('michimono-labs', $organization->slug);
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_a_non_administrator_cannot_open_the_settings_screen(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertForbidden();
    }

    #[DataProvider('nonAdministrativeRoles')]
    public function test_a_non_administrator_cannot_update_settings(Role $role): void
    {
        [$user, $organization] = $this->memberHolding($role, [
            'name' => 'Michimono Labs',
            'timezone' => 'UTC',
        ]);

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => 'Second Studio',
                'timezone' => 'Asia/Tokyo',
            ])
            ->assertForbidden();

        $organization->refresh();

        $this->assertSame('Michimono Labs', $organization->name);
        $this->assertSame('UTC', $organization->timezone);
    }

    public function test_the_settings_link_is_offered_only_to_administrators(): void
    {
        [$owner, $ownersOrganization] = $this->memberHolding(Role::Owner);
        [$editor, $editorsOrganization] = $this->memberHolding(Role::Editor);

        $this->actingAs($owner)
            ->get(route('organizations.show', $ownersOrganization))
            ->assertOk()
            ->assertSee(route('organization-settings.edit', $ownersOrganization), false);

        $this->actingAs($editor)
            ->get(route('organizations.show', $editorsOrganization))
            ->assertOk()
            ->assertDontSee(route('organization-settings.edit', $editorsOrganization), false);
    }

    public function test_an_unknown_timezone_identifier_is_rejected(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, ['timezone' => 'UTC']);

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => $organization->name,
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $organization->refresh()->timezone);
    }

    public function test_a_fixed_utc_offset_is_rejected_so_only_identifiers_are_stored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, ['timezone' => 'UTC']);

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => $organization->name,
                'timezone' => '+02:00',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $organization->refresh()->timezone);
    }

    public function test_the_name_is_required(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, ['name' => 'Michimono Labs']);

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => '',
                'timezone' => 'UTC',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('Michimono Labs', $organization->refresh()->name);
    }

    public function test_a_plan_in_the_payload_is_ignored(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Owner, ['plan' => null]);

        $this->actingAs($user)
            ->patch(route('organization-settings.update', $organization), [
                'name' => 'Michimono Labs',
                'timezone' => 'UTC',
                'plan' => 'paid',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($organization->refresh()->plan);
    }

    public function test_a_dangerous_name_is_escaped_on_the_settings_screen(): void
    {
        $name = '<script>alert("xss")</script>';
        [$user, $organization] = $this->memberHolding(Role::Owner, ['name' => $name]);

        $this->actingAs($user)
            ->get(route('organization-settings.edit', $organization))
            ->assertOk()
            ->assertDontSee($name, false)
            ->assertSee($name);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function administrativeRoles(): array
    {
        return [
            'owner' => [Role::Owner],
            'admin' => [Role::Admin],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonAdministrativeRoles(): array
    {
        return [
            'editor' => [Role::Editor],
            'viewer' => [Role::Viewer],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role, array $attributes = []): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create($attributes)];
    }
}
