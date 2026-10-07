<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());

        $users = User::query()
            ->withCount('organizations')
            ->addSelect([
                'last_seen_at' => DB::table('sessions')
                    ->selectRaw('max(last_activity)')
                    ->whereColumn('sessions.user_id', 'users.id'),
            ])
            ->when(
                $search !== '',
                fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                }),
            )
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function show(User $user): View
    {
        $memberships = $user->memberships()
            ->with('organization')
            ->get()
            ->sortBy(fn ($membership): string => $membership->organization->name)
            ->values();

        $ownedOrganizations = $memberships
            ->filter(fn ($membership): bool => $membership->role === Role::Owner)
            ->map(fn ($membership) => $membership->organization)
            ->values();

        $sessions = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): object => (object) [
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => Carbon::createFromTimestamp((int) $session->last_activity),
            ]);

        $auditEntries = AdminActionLog::query()
            ->with('admin')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(30)
            ->get();

        $lastSeen = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->max('last_activity');

        return view('admin.users.show', [
            'user' => $user,
            'memberships' => $memberships,
            'ownedOrganizations' => $ownedOrganizations,
            'sessions' => $sessions,
            'auditEntries' => $auditEntries,
            'lastSeen' => $lastSeen !== null
                ? Carbon::createFromTimestamp((int) $lastSeen)
                : null,
        ]);
    }
}
