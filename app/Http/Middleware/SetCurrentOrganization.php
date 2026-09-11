<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    /**
     * The organization last worked in. Only a request without one in its URL
     * reads this, to choose where to send the user.
     */
    public const SESSION_KEY = 'current_organization_id';

    public function __construct(private CurrentOrganization $current) {}

    /**
     * Bind the request to the organization named in its URL.
     *
     * Membership is what makes an organization visible at all, so a
     * non-member is told the URL does not exist rather than that it is
     * forbidden. This runs behind the `auth` middleware, and fails closed if
     * it is ever applied without it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $request->route('organization');

        if (! $user instanceof User || ! $organization instanceof Organization) {
            abort(404);
        }

        $membership = $user->memberships()->firstWhere('organization_id', $organization->getKey());

        if ($membership === null) {
            abort(404);
        }

        $membership->setRelation('organization', $organization);

        $this->current->set($membership);

        $request->session()->put(self::SESSION_KEY, $organization->getKey());

        // Keeps every route() call made inside an organization in that
        // organization, so no view or redirect has to pass the id by hand.
        URL::defaults(['organization' => $organization->getKey()]);

        return $next($request);
    }
}
