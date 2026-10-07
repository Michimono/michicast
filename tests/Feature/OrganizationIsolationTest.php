<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Organization isolation suite.
 *
 * Proves systematically that no organization can read or affect another's data.
 * Individual feature tests cover their own scoping; this suite walks the
 * boundary as a whole so a missed binding or forgotten middleware fails here.
 *
 * Pattern for asserting isolation on any route added later
 * ---------------------------------------------------------
 * 1. Register the route under `organizations/{organization}` with
 *    `SetCurrentOrganization` and `scopeBindings()` (see routes/web.php).
 * 2. This suite discovers every such route from the router. A newly added
 *    organization-scoped route is covered automatically; if it omits the
 *    middleware or scoped bindings, the structural assertions fail.
 * 3. For each HTTP verb on each route the suite hits:
 *    - the foreign organization's URL (attacker is not a member) → 404/403
 *    - the attacker's own organization URL with nested ids that belong to
 *      the foreign organization → 404/403 (scoped bindings)
 * 4. Responses must be 403 or 404, never leak distinctive foreign markers,
 *    and mutating verbs must leave foreign records unchanged.
 * 5. Separate cases verify listings and Eloquent relationships are scoped
 *    in the data layer, not only hidden by the UI.
 *
 * Prefer 404 over 403 when that matches SetCurrentOrganization and scoped
 * bindings; both are accepted so policy-first denials still pass.
 */
class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const FOREIGN_ORG = 'Victim Org Isolation Marker';

    private const FOREIGN_PROJECT = 'Victim Project Isolation Marker';

    private const FOREIGN_SOURCE = 'Victim Source Isolation Marker';

    private const FOREIGN_DESTINATION = 'Victim Destination Isolation Marker';

    private const FOREIGN_BROADCAST = 'Victim Flow Isolation Marker';

    private const FOREIGN_INVITE_EMAIL = 'victim-invite-isolation@example.test';

    private const FOREIGN_MEMBER_EMAIL = 'victim-member-isolation@example.test';

    /**
     * @var list<string>
     */
    private const FOREIGN_MARKERS = [
        self::FOREIGN_ORG,
        self::FOREIGN_PROJECT,
        self::FOREIGN_SOURCE,
        self::FOREIGN_DESTINATION,
        self::FOREIGN_BROADCAST,
        self::FOREIGN_INVITE_EMAIL,
        self::FOREIGN_MEMBER_EMAIL,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_every_organization_parameter_route_uses_set_current_organization(): void
    {
        $routes = $this->organizationScopedRoutes();

        $this->assertNotEmpty($routes, 'Expected organization-scoped routes to exist.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertTrue(
                in_array(SetCurrentOrganization::class, $middleware, true),
                sprintf(
                    'Route [%s] %s must use %s so a non-member cannot probe it.',
                    implode('|', $route->methods()),
                    $route->uri(),
                    SetCurrentOrganization::class,
                ),
            );
        }
    }

    public function test_admin_organization_routes_use_platform_admin_instead_of_set_current_organization(): void
    {
        $routes = collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => str_contains($route->uri(), 'organizations/{organization}'))
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin/'))
            ->values()
            ->all();

        $this->assertNotEmpty($routes, 'Expected admin organization routes to exist.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertTrue(
                in_array(EnsurePlatformAdmin::class, $middleware, true),
                sprintf(
                    'Route [%s] %s must use %s.',
                    implode('|', $route->methods()),
                    $route->uri(),
                    EnsurePlatformAdmin::class,
                ),
            );

            $this->assertFalse(
                in_array(SetCurrentOrganization::class, $middleware, true),
                sprintf(
                    'Route [%s] %s must not use %s (platform admin stays out of tenant context).',
                    implode('|', $route->methods()),
                    $route->uri(),
                    SetCurrentOrganization::class,
                ),
            );
        }
    }

    public function test_nested_organization_routes_enforce_scoped_bindings(): void
    {
        foreach ($this->organizationScopedRoutes() as $route) {
            if (count($route->parameterNames()) < 2) {
                continue;
            }

            $this->assertTrue(
                $route->enforcesScopedBindings(),
                sprintf(
                    'Route [%s] %s has nested parameters and must enforce scoped bindings.',
                    implode('|', $route->methods()),
                    $route->uri(),
                ),
            );
        }
    }

    public function test_a_member_of_another_organization_is_denied_every_organization_scoped_route(): void
    {
        $fixtures = $this->isolationFixtures();
        $touched = 0;

        foreach ($this->organizationScopedRoutes() as $route) {
            foreach ($this->verbsFor($route) as $method) {
                $parameters = $this->parametersFor($route, $fixtures, useForeignOrganization: true);
                $uri = $this->uriFor($route, $parameters);

                $response = $this->actingAs($fixtures['attacker'])
                    ->call($method, $uri);

                $this->assertDeniedWithoutLeak($response, $method, $uri, 'foreign organization URL');
                $touched++;
            }
        }

        $this->assertGreaterThan(
            50,
            $touched,
            'Expected to exercise the full organization-scoped route surface.',
        );

        $this->assertForeignRecordsUntouched($fixtures);
    }

    public function test_direct_ids_from_another_organization_are_denied_under_the_attackers_organization(): void
    {
        $fixtures = $this->isolationFixtures();
        $touched = 0;

        foreach ($this->organizationScopedRoutes() as $route) {
            if (count($route->parameterNames()) < 2) {
                continue;
            }

            foreach ($this->verbsFor($route) as $method) {
                $parameters = $this->parametersFor($route, $fixtures, useForeignOrganization: false);
                $uri = $this->uriFor($route, $parameters);

                $response = $this->actingAs($fixtures['attacker'])
                    ->call($method, $uri);

                $this->assertDeniedWithoutLeak($response, $method, $uri, 'foreign nested ids under own organization');
                $touched++;
            }
        }

        $this->assertGreaterThan(
            40,
            $touched,
            'Expected nested organization routes to be exercised with foreign ids.',
        );

        $this->assertForeignRecordsUntouched($fixtures);
    }

    public function test_mismatched_child_ids_under_own_parents_are_denied(): void
    {
        $fixtures = $this->isolationFixtures();

        $cases = [
            [
                'method' => 'GET',
                'uri' => route('broadcast-runs.show', [
                    $fixtures['attackerOrganization'],
                    $fixtures['attackerProject'],
                    $fixtures['foreignRun'],
                ], absolute: false),
            ],
            [
                'method' => 'POST',
                'uri' => route('delivery-retries.store', [
                    $fixtures['attackerOrganization'],
                    $fixtures['attackerBroadcast'],
                    $fixtures['foreignDelivery'],
                ], absolute: false),
            ],
            [
                'method' => 'DELETE',
                'uri' => route('members.destroy', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignMember'],
                ], absolute: false),
            ],
            [
                'method' => 'DELETE',
                'uri' => route('invitations.destroy', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignInvitation'],
                ], absolute: false),
            ],
            [
                'method' => 'GET',
                'uri' => route('sources.edit', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignSource'],
                ], absolute: false),
            ],
            [
                'method' => 'GET',
                'uri' => route('destinations.edit', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignDestination'],
                ], absolute: false),
            ],
            [
                'method' => 'GET',
                'uri' => route('flows.edit', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignBroadcast'],
                ], absolute: false),
            ],
            [
                'method' => 'GET',
                'uri' => route('broadcasts.show', [
                    $fixtures['attackerOrganization'],
                    $fixtures['foreignProject'],
                ], absolute: false),
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->actingAs($fixtures['attacker'])
                ->call($case['method'], $case['uri']);

            $this->assertDeniedWithoutLeak(
                $response,
                $case['method'],
                $case['uri'],
                'mismatched child id under own parent',
            );
        }

        $this->assertForeignRecordsUntouched($fixtures);
    }

    public function test_organization_listings_never_reveal_another_organizations_records(): void
    {
        $fixtures = $this->isolationFixtures();
        $organization = $fixtures['attackerOrganization'];

        $listingRoutes = [
            route('organizations.show', $organization, absolute: false),
            route('broadcasts.index', $organization, absolute: false),
            route('archived-broadcasts.index', $organization, absolute: false),
            route('members.index', $organization, absolute: false),
            route('broadcast-runs.index', [$organization, $fixtures['attackerProject']], absolute: false),
            route('broadcast-deliveries.index', [$organization, $fixtures['attackerBroadcast']], absolute: false),
            route('broadcast-pipelines.index', [$organization, $fixtures['attackerProject']], absolute: false),
        ];

        foreach ($listingRoutes as $uri) {
            $response = $this->actingAs($fixtures['attacker'])->get($uri);

            $this->assertTrue(
                $response->isSuccessful(),
                sprintf('Expected listing %s to be reachable for a member, got %d.', $uri, $response->status()),
            );

            $this->assertResponseDoesNotLeakForeignMarkers($response, 'GET', $uri);
        }
    }

    public function test_relationship_queries_are_scoped_to_the_organization(): void
    {
        $fixtures = $this->isolationFixtures();
        $own = $fixtures['attackerOrganization'];

        $this->assertTrue($own->projects()->whereKey($fixtures['attackerProject'])->exists());
        $this->assertFalse($own->projects()->whereKey($fixtures['foreignProject'])->exists());

        $this->assertTrue($own->sources()->whereKey($fixtures['attackerSource'])->exists());
        $this->assertFalse($own->sources()->whereKey($fixtures['foreignSource'])->exists());

        $this->assertTrue($own->destinations()->whereKey($fixtures['attackerDestination'])->exists());
        $this->assertFalse($own->destinations()->whereKey($fixtures['foreignDestination'])->exists());

        $this->assertTrue($own->broadcasts()->whereKey($fixtures['attackerBroadcast'])->exists());
        $this->assertFalse($own->broadcasts()->whereKey($fixtures['foreignBroadcast'])->exists());

        $this->assertTrue($own->members()->whereKey($fixtures['attackerMember']->getKey())->exists());
        $this->assertFalse($own->members()->whereKey($fixtures['foreignMember'])->exists());

        $this->assertTrue($own->invitations()->whereKey($fixtures['attackerInvitation'])->exists());
        $this->assertFalse($own->invitations()->whereKey($fixtures['foreignInvitation'])->exists());

        $this->assertTrue($fixtures['attackerProject']->runs()->whereKey($fixtures['attackerRun'])->exists());
        $this->assertFalse($fixtures['attackerProject']->runs()->whereKey($fixtures['foreignRun'])->exists());

        $this->assertTrue($fixtures['attackerBroadcast']->deliveries()->whereKey($fixtures['attackerDelivery'])->exists());
        $this->assertFalse($fixtures['attackerBroadcast']->deliveries()->whereKey($fixtures['foreignDelivery'])->exists());
    }

    /**
     * @return list<Route>
     */
    private function organizationScopedRoutes(): array
    {
        return collect(RouteFacade::getRoutes())
            ->filter(fn (Route $route): bool => str_contains($route->uri(), 'organizations/{organization}'))
            ->reject(fn (Route $route): bool => str_starts_with($route->uri(), 'admin/'))
            ->sortBy(fn (Route $route): string => $route->uri())
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function verbsFor(Route $route): array
    {
        $methods = array_values(array_filter(
            $route->methods(),
            fn (string $method): bool => ! in_array($method, ['HEAD', 'OPTIONS'], true),
        ));

        // Soft redirects register as ANY; exercising GET is enough isolation coverage.
        if ($methods === ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] || in_array('ANY', $route->methods(), true)) {
            return ['GET'];
        }

        if ($methods === []) {
            return ['GET'];
        }

        return $methods;
    }

    /**
     * @param  array<string, mixed>  $fixtures
     * @return array<string, int|string>
     */
    private function parametersFor(Route $route, array $fixtures, bool $useForeignOrganization): array
    {
        $organization = $useForeignOrganization
            ? $fixtures['foreignOrganization']
            : $fixtures['attackerOrganization'];

        $map = [
            'organization' => $organization->getRouteKey(),
            'project' => $fixtures['foreignProject']->getRouteKey(),
            'source' => $fixtures['foreignSource']->getRouteKey(),
            'destination' => $fixtures['foreignDestination']->getRouteKey(),
            'broadcast' => $fixtures['foreignBroadcast']->getRouteKey(),
            'run' => $fixtures['foreignRun']->getRouteKey(),
            'member' => $fixtures['foreignMember']->getRouteKey(),
            'invitation' => $fixtures['foreignInvitation']->getRouteKey(),
            'delivery' => $fixtures['foreignDelivery']->getRouteKey(),
        ];

        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $this->assertArrayHasKey(
                $name,
                $map,
                sprintf('Route %s uses {%s}; teach the isolation suite how to supply it.', $route->uri(), $name),
            );

            $parameters[$name] = $map[$name];
        }

        return $parameters;
    }

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function uriFor(Route $route, array $parameters): string
    {
        $uri = $route->uri();

        foreach ($parameters as $name => $value) {
            $uri = str_replace(
                ['{'.$name.'}', '{'.$name.'?}'],
                [(string) $value, (string) $value],
                $uri,
            );
        }

        $this->assertStringNotContainsString(
            '{',
            $uri,
            sprintf('Failed to substitute parameters for %s.', $route->uri()),
        );

        return '/'.$uri;
    }

    private function assertDeniedWithoutLeak(TestResponse $response, string $method, string $uri, string $case): void
    {
        $status = $response->status();

        $this->assertContains(
            $status,
            [403, 404],
            sprintf(
                '%s %s (%s) must deny cross-tenant access with 403 or 404, got %d.',
                $method,
                $uri,
                $case,
                $status,
            ),
        );

        $this->assertResponseDoesNotLeakForeignMarkers($response, $method, $uri);
    }

    private function assertResponseDoesNotLeakForeignMarkers(TestResponse $response, string $method, string $uri): void
    {
        $haystack = strtolower(
            $response->getContent().
            $response->headers->get('Location', '').
            json_encode($response->headers->all())
        );

        foreach (self::FOREIGN_MARKERS as $marker) {
            $this->assertStringNotContainsString(
                strtolower($marker),
                $haystack,
                sprintf('%s %s leaked foreign marker [%s].', $method, $uri, $marker),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $fixtures
     */
    private function assertForeignRecordsUntouched(array $fixtures): void
    {
        $this->assertSame(self::FOREIGN_ORG, $fixtures['foreignOrganization']->fresh()->name);
        $this->assertSame(self::FOREIGN_PROJECT, $fixtures['foreignProject']->fresh()->name);
        $this->assertSame(self::FOREIGN_SOURCE, $fixtures['foreignSource']->fresh()->name);
        $this->assertSame(self::FOREIGN_DESTINATION, $fixtures['foreignDestination']->fresh()->name);
        $this->assertSame(self::FOREIGN_BROADCAST, $fixtures['foreignBroadcast']->fresh()->name);
        $this->assertNotNull($fixtures['foreignMember']->fresh());
        $this->assertNotNull($fixtures['foreignInvitation']->fresh());
        $this->assertNotNull($fixtures['foreignRun']->fresh());
        $this->assertNull($fixtures['foreignDelivery']->fresh()->retry_requested_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function isolationFixtures(): array
    {
        $attacker = User::factory()->create();
        $attackerOrganization = Organization::factory()->ownedBy($attacker)->create([
            'name' => 'Attacker Org Isolation Safe',
        ]);
        $attackerMember = $attackerOrganization->members()->where('user_id', $attacker->getKey())->sole();
        $attackerProject = Project::factory()->for($attackerOrganization)->create([
            'name' => 'Attacker Project Isolation Safe',
        ]);
        $attackerSource = Source::factory()->for($attackerProject)->create([
            'name' => 'Attacker Source Isolation Safe',
        ]);
        $attackerDestination = Destination::factory()->for($attackerProject)->create([
            'name' => 'Attacker Destination Isolation Safe',
        ]);
        $attackerBroadcast = Broadcast::factory()->for($attackerProject)->create([
            'name' => 'Attacker Flow Isolation Safe',
            'source_id' => $attackerSource->getKey(),
            'destination_id' => $attackerDestination->getKey(),
        ]);
        $attackerRun = ProjectRun::factory()->for($attackerProject)->create();
        $attackerItem = ContentItem::factory()->for($attackerProject)->forSource($attackerSource->getKey())->create();
        $attackerDelivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $attackerItem->getKey(),
            'broadcast_id' => $attackerBroadcast->getKey(),
            'destination_id' => $attackerDestination->getKey(),
        ]);
        $attackerInvitation = OrganizationInvitation::factory()->for($attackerOrganization)->create([
            'invited_by' => $attacker->getKey(),
            'email' => 'attacker-invite-isolation@example.test',
        ]);

        $victimOwner = User::factory()->create([
            'email' => self::FOREIGN_MEMBER_EMAIL,
        ]);
        $foreignOrganization = Organization::factory()->ownedBy($victimOwner)->create([
            'name' => self::FOREIGN_ORG,
        ]);
        $foreignMember = $foreignOrganization->members()->where('user_id', $victimOwner->getKey())->sole();
        $extraForeignMember = OrganizationMember::factory()
            ->for($foreignOrganization)
            ->role(Role::Viewer)
            ->create();
        $foreignProject = Project::factory()->for($foreignOrganization)->create([
            'name' => self::FOREIGN_PROJECT,
        ]);
        $foreignSource = Source::factory()->for($foreignProject)->create([
            'name' => self::FOREIGN_SOURCE,
        ]);
        $foreignDestination = Destination::factory()->for($foreignProject)->create([
            'name' => self::FOREIGN_DESTINATION,
        ]);
        $foreignBroadcast = Broadcast::factory()->for($foreignProject)->create([
            'name' => self::FOREIGN_BROADCAST,
            'source_id' => $foreignSource->getKey(),
            'destination_id' => $foreignDestination->getKey(),
        ]);
        $foreignRun = ProjectRun::factory()->for($foreignProject)->create();
        $foreignItem = ContentItem::factory()->for($foreignProject)->forSource($foreignSource->getKey())->create([
            'title' => 'Victim Content Isolation Marker',
        ]);
        $foreignDelivery = ContentDelivery::factory()->transientlyFailed()->create([
            'content_item_id' => $foreignItem->getKey(),
            'broadcast_id' => $foreignBroadcast->getKey(),
            'destination_id' => $foreignDestination->getKey(),
        ]);
        $foreignInvitation = OrganizationInvitation::factory()->for($foreignOrganization)->create([
            'invited_by' => $victimOwner->getKey(),
            'email' => self::FOREIGN_INVITE_EMAIL,
        ]);

        return [
            'attacker' => $attacker,
            'attackerOrganization' => $attackerOrganization,
            'attackerMember' => $attackerMember,
            'attackerProject' => $attackerProject,
            'attackerSource' => $attackerSource,
            'attackerDestination' => $attackerDestination,
            'attackerBroadcast' => $attackerBroadcast,
            'attackerRun' => $attackerRun,
            'attackerDelivery' => $attackerDelivery,
            'attackerInvitation' => $attackerInvitation,
            'foreignOrganization' => $foreignOrganization,
            'foreignMember' => $extraForeignMember,
            'foreignOwnerMember' => $foreignMember,
            'foreignProject' => $foreignProject,
            'foreignSource' => $foreignSource,
            'foreignDestination' => $foreignDestination,
            'foreignBroadcast' => $foreignBroadcast,
            'foreignRun' => $foreignRun,
            'foreignDelivery' => $foreignDelivery,
            'foreignInvitation' => $foreignInvitation,
        ];
    }
}
