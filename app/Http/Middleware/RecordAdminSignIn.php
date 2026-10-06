<?php

namespace App\Http\Middleware;

use App\Enums\AdminAction;
use App\Models\User;
use App\Support\RecordAdminAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record one SignedIn audit row the first time an admin reaches /admin after
 * (re)confirming their password. Later issues that add mutations keep using
 * RecordAdminAction directly inside their transactions.
 */
class RecordAdminSignIn
{
    private const SESSION_KEY = 'admin.sign_in_recorded_for';

    public function __construct(private RecordAdminAction $recordAdminAction) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (
            $user instanceof User
            && is_int($confirmedAt)
            && $request->session()->get(self::SESSION_KEY) !== $confirmedAt
        ) {
            ($this->recordAdminAction)(
                AdminAction::SignedIn,
                $user,
            );

            $request->session()->put(self::SESSION_KEY, $confirmedAt);
        }

        return $next($request);
    }
}
