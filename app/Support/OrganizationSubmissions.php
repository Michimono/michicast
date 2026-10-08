<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers which organization each rendered create form produced.
 *
 * The form carries a token generated when it renders. Submitting the same
 * token again (a double click, or back and resubmit) returns the organization
 * it already created instead of making another, and concurrent repeats wait
 * on a lock rather than racing. A request without a token is always fresh.
 */
class OrganizationSubmissions
{
    /**
     * How long a fulfilled token is remembered.
     */
    private const REMEMBER_SECONDS = 86400;

    /**
     * How long one submission may hold the lock, and how long a repeat waits.
     */
    private const LOCK_SECONDS = 30;

    private const WAIT_SECONDS = 10;

    /**
     * The id of the organization this token already created, if any.
     */
    public function organizationIdFor(User $user, string $token): ?int
    {
        $organizationId = Cache::get($this->key($user, $token));

        return is_int($organizationId) ? $organizationId : null;
    }

    /**
     * Create at most one organization per user and token.
     *
     * @param  Closure(): Organization  $create
     *
     * @throws LockTimeoutException
     */
    public function once(User $user, string $token, Closure $create): Organization
    {
        return Cache::lock($this->key($user, $token).':lock', self::LOCK_SECONDS)
            ->block(self::WAIT_SECONDS, function () use ($user, $token, $create): Organization {
                $organizationId = $this->organizationIdFor($user, $token);
                $existing = $organizationId === null ? null : Organization::query()->find($organizationId);

                if ($existing !== null) {
                    return $existing;
                }

                $organization = $create();

                Cache::put($this->key($user, $token), $organization->getKey(), self::REMEMBER_SECONDS);

                return $organization;
            });
    }

    private function key(User $user, string $token): string
    {
        return 'organization-submissions:'.$user->getKey().':'.$token;
    }
}
