<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * Hide the admin area from anyone who is not a platform admin, and from
     * admins whose address is outside ADMIN_ALLOWED_IPS when that list is set.
     *
     * A miss answers 404 rather than 403 so the area cannot be probed, matching
     * how SetCurrentOrganization hides other organizations.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || Gate::forUser($user)->denies('accessAdmin')) {
            abort(404);
        }

        $allowedIps = config('michicast.admin.allowed_ips', []);

        if (is_array($allowedIps) && $allowedIps !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowedIps)) {
            abort(404);
        }

        return $next($request);
    }
}
