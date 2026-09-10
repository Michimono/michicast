<?php

namespace App\Http\Middleware;

use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    /**
     * Where the chosen organization survives between requests.
     */
    public const SESSION_KEY = 'current_organization_id';

    public function __construct(private CurrentOrganization $current) {}

    /**
     * Resolve the one organization this request acts in, or send a user who
     * belongs to none to the creation screen.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $membership = $this->membershipFor($user, $request->session()->get(self::SESSION_KEY));

        if ($membership === null) {
            return redirect()->route('organizations.create');
        }

        $request->session()->put(self::SESSION_KEY, $membership->organization_id);

        $this->current->set($membership);

        return $next($request);
    }

    /**
     * The chosen organization when the user still belongs to it, otherwise
     * their oldest membership. A stale or foreign id is never honoured.
     */
    private function membershipFor(User $user, mixed $organizationId): ?OrganizationMember
    {
        $chosen = is_numeric($organizationId)
            ? $user->memberships()->with('organization')->firstWhere('organization_id', (int) $organizationId)
            : null;

        return $chosen ?? $user->memberships()->with('organization')->oldest('id')->first();
    }
}
