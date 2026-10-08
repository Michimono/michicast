<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FirstOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const SUCCESS = 'You’re all set — your organization has been created.';

    public function test_a_user_without_organizations_gets_the_first_organization_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertSee('Your workspace starts here')
            ->assertSee('Good things happen when everything connects.')
            ->assertSee('Bring your sources together. Shape the content. Send it where it needs to go — automatically.')
            ->assertSeeInOrder(['Sources', 'Mapping', 'Destinations'])
            ->assertSee('Create your organization')
            ->assertSee('Choose a name for the workspace where your broadcasts, sources and destinations will live.')
            ->assertSee('You can change these details later.')
            ->assertSee('A simpler way to keep everything connected.')
            ->assertSee('Signed in as '.$user->email.'.')
            ->assertSee('action="'.route('session.destroy').'"', false)
            ->assertDontSee('aria-label="Account"', false)
            ->assertSee('Organization name')
            ->assertSee('placeholder="e.g. Pokémon News"', false)
            ->assertSee('<label class="text-sm font-medium" for="timezone">Timezone</label>', false)
            ->assertSee('Broadcast schedules use this timezone, regardless of who views them.')
            ->assertSee('Create organization')
            ->assertSee('Creating organization…')
            ->assertSee('aria-live="polite"', false)
            ->assertSee('data-pending-form', false)
            ->assertSee('maxlength="255"', false)
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('onsubmit', false)
            ->assertSeeInOrder(['name="submission"', 'value="'], false);
    }

    public function test_a_guest_is_sent_to_login_and_back_to_the_page_afterwards(): void
    {
        $user = User::factory()->create();

        $this->get(route('organizations.create'))->assertRedirect(route('session.create'));

        $this->post(route('session.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('organizations.create'));
    }

    public function test_a_member_keeps_the_existing_routing_and_the_regular_create_page(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('organizations.show', $organization));

        $this->actingAs($user)
            ->get(route('organizations.create'))
            ->assertOk()
            ->assertSee('New organization')
            ->assertSee('aria-label="Account"', false)
            ->assertDontSee('Your workspace starts here')
            ->assertDontSee('Good things happen when everything connects.');
    }

    public function test_an_empty_or_blank_name_is_refused(): void
    {
        $user = User::factory()->create();

        foreach (['', '   '] as $name) {
            $this->actingAs($user)
                ->post(route('organizations.store'), ['name' => $name, 'timezone' => 'UTC'])
                ->assertSessionHasErrors(['name' => 'Enter an organization name.']);
        }

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => str_repeat('a', 256), 'timezone' => 'UTC'])
            ->assertSessionHasErrors(['name' => 'Organization name must be no more than 255 characters.']);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_an_invalid_timezone_is_refused_by_the_server(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('organizations.store'), ['name' => 'Pokémon News', 'timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors(['timezone' => 'Select a valid timezone.']);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_a_valid_submission_creates_the_organization_with_its_owner_and_opens_it(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('organizations.store'), [
            'name' => '  Pokémon News  ',
            'timezone' => 'Europe/Madrid',
            'submission' => (string) Str::uuid(),
        ]);

        $organization = Organization::query()->sole();

        $this->assertSame('Pokémon News', $organization->name);
        $this->assertSame('Europe/Madrid', $organization->timezone);
        $this->assertSame(Role::Owner, $organization->roleFor($user));
        $this->assertSame(1, $user->memberships()->count());

        $response->assertRedirect(route('organizations.show', $organization));
        $response->assertSessionHas('success', self::SUCCESS);
        $this->assertSame($organization->getKey(), session(SetCurrentOrganization::SESSION_KEY));

        $page = $this->actingAs($user)->get(route('organizations.show', $organization))->assertOk();

        $page->assertSee('Your workspace is ready.');
        $this->assertSame(1, substr_count($page->getContent(), e(self::SUCCESS)));
    }

    public function test_a_failed_submission_keeps_the_values_and_focuses_the_invalid_field(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($user)
            ->from(route('organizations.create'))
            ->followingRedirects()
            ->post(route('organizations.store'), ['name' => 'Pokémon News', 'timezone' => 'Mars/Olympus_Mons'])
            ->assertOk()
            ->assertSee('value="Pokémon News"', false)
            ->assertSee('Select a valid timezone.')
            ->assertSeeInOrder(['id="name"', 'id="timezone"', 'aria-invalid="true"', 'autofocus="autofocus"'], false);

        $this->actingAs($user)
            ->from(route('organizations.create'))
            ->followingRedirects()
            ->post(route('organizations.store'), ['name' => '', 'timezone' => 'Asia/Tokyo'])
            ->assertOk()
            ->assertSee('Enter an organization name.')
            ->assertSee('<option value="Asia/Tokyo" selected>', false)
            ->assertSeeInOrder(['id="name"', 'autofocus="autofocus"', 'id="timezone"'], false);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_a_server_error_keeps_the_form_and_never_reports_success(): void
    {
        Exceptions::fake();

        Organization::creating(function (): never {
            throw new RuntimeException('SQLSTATE[HY000]: database is gone');
        });

        $user = User::factory()->create();
        $form = ['name' => 'Pokémon News', 'timezone' => 'Europe/Madrid', 'submission' => (string) Str::uuid()];

        $this->actingAs($user)
            ->from(route('organizations.create'))
            ->post(route('organizations.store'), $form)
            ->assertRedirect(route('organizations.create'))
            ->assertSessionHasErrors(['organization' => 'We couldn’t create your organization. Please try again.'])
            ->assertSessionMissing('success');

        $this->actingAs($user)
            ->from(route('organizations.create'))
            ->followingRedirects()
            ->post(route('organizations.store'), $form)
            ->assertOk()
            ->assertSee('We couldn’t create your organization. Please try again.')
            ->assertSee('data-focus-on-load', false)
            ->assertSee('value="Pokémon News"', false)
            ->assertSee('<option value="Europe/Madrid" selected>', false)
            ->assertDontSee('database is gone')
            ->assertDontSee(self::SUCCESS);

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_members', 0);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_server_refuses_requests_that_bypass_the_page(): void
    {
        $this->post(route('organizations.store'), ['name' => 'Pokémon News', 'timezone' => 'UTC'])
            ->assertRedirect(route('session.create'));

        $banned = User::factory()->banned()->create();

        $this->actingAs($banned)
            ->post(route('organizations.store'), ['name' => 'Pokémon News', 'timezone' => 'UTC'])
            ->assertRedirect(route('session.create'));

        config(['michicast.quotas.organizations_per_user' => 1]);
        $owner = User::factory()->create();
        Organization::factory()->ownedBy($owner)->create();

        $this->actingAs($owner)
            ->post(route('organizations.store'), [
                'name' => 'Pokémon News',
                'timezone' => 'UTC',
                'submission' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors(['name' => 'You can own at most 1 organization.']);

        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_resubmitting_the_same_form_does_not_create_a_second_organization(): void
    {
        config(['michicast.quotas.organizations_per_user' => 1]);

        $user = User::factory()->create();
        $form = ['name' => 'Pokémon News', 'timezone' => 'UTC', 'submission' => (string) Str::uuid()];

        $first = $this->actingAs($user)->post(route('organizations.store'), $form);
        $organization = Organization::query()->sole();

        // At the one-organization limit, the repeat is still sent to the
        // organization it made rather than refused as one too many.
        $this->actingAs($user)
            ->post(route('organizations.store'), $form)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('organizations.show', $organization));

        $first->assertRedirect(route('organizations.show', $organization));
        $this->assertDatabaseCount('organizations', 1);
        $this->assertSame(1, $user->memberships()->count());
    }

    public function test_a_new_form_is_a_new_submission(): void
    {
        $user = User::factory()->create();

        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $submission) {
            $this->actingAs($user)
                ->post(route('organizations.store'), ['name' => 'Pokémon News', 'timezone' => 'UTC', 'submission' => $submission])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('organizations', 2);
    }

    public function test_the_first_broadcast_empty_state_only_shows_without_broadcasts(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->ownedBy($user)->create();

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Your workspace is ready.')
            ->assertSee('Create your first broadcast to start connecting your sources to your destinations.')
            ->assertSee('Create broadcast')
            ->assertSee(route('broadcasts.create', $organization), false)
            ->assertDontSee('+ New broadcast');

        Project::factory()->for($organization)->create(['name' => 'Morning Digest']);

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Morning Digest')
            ->assertSee('+ New broadcast')
            ->assertDontSee('Your workspace is ready.');
    }

    public function test_a_viewer_sees_the_empty_state_without_the_create_button(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Viewer)->create();

        $this->actingAs($user)
            ->get(route('organizations.show', $organization))
            ->assertOk()
            ->assertSee('Your workspace is ready.')
            ->assertDontSee(route('broadcasts.create', $organization), false);
    }

    public function test_the_header_lockup_follows_the_dashboard_when_signed_in(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('href="'.route('dashboard').'"', false);

        $this->actingAs(User::factory()->create())
            ->get(route('organizations.create'))
            ->assertSee('href="'.route('dashboard').'"', false);
    }
}
