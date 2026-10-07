<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign a banned user out mid-request and send them to login with a neutral message.
 *
 * Appended to the web group so every authenticated page (including invitation
 * acceptance outside the auth middleware stack) shares one definition with login.
 */
class EnsureUserIsNotBanned
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isBanned()) {
            return $next($request);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('session.create')
            ->with('error', 'Your session has ended. Please log in again.');
    }
}
