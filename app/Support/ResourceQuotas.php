<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;

/**
 * Count-based quota checks for organizations, broadcasts, sources,
 * destinations and members. Limits come from Quotas (plan / override) except
 * organizations-per-user, which is a global config cap.
 *
 * Counts are plain queries — no denormalized counters. Null limit means
 * unlimited. At-limit refuses new creation only; existing records stay.
 */
class ResourceQuotas
{
    public function __construct(private Quotas $quotas) {}

    /**
     * How many organizations this user owns (Role::Owner memberships).
     */
    public function organizationsOwnedBy(User $user): int
    {
        return $user->memberships()
            ->where('role', Role::Owner)
            ->count();
    }

    /**
     * Cap on organizations one user may own. Always a non-negative integer from config.
     */
    public function organizationsPerUserLimit(): int
    {
        return max(0, (int) config('michicast.quotas.organizations_per_user', 3));
    }

    /**
     * Whether creating another owned organization would exceed the per-user cap.
     * Platform admins are never blocked.
     */
    public function wouldExceedOrganizationsPerUser(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return false;
        }

        return $this->organizationsOwnedBy($user) >= $this->organizationsPerUserLimit();
    }

    public function organizationsPerUserMessage(): string
    {
        $limit = $this->organizationsPerUserLimit();

        return "You can own at most {$limit} ".str('organization')->plural($limit).'.';
    }

    /**
     * Current usage for a stock (non-rate) metric.
     */
    public function count(Organization $organization, QuotaMetric $metric): int
    {
        return match ($metric) {
            QuotaMetric::ActiveBroadcasts => $organization->broadcasts()
                ->runnable()
                ->count(),
            QuotaMetric::Sources => $organization->sources()->count(),
            QuotaMetric::Destinations => $organization->destinations()->count(),
            QuotaMetric::Members => $organization->members()->count()
                + $organization->invitations()->pending()->count(),
            QuotaMetric::SourceFetches, QuotaMetric::Deliveries => throw new InvalidArgumentException(
                "ResourceQuotas::count does not apply to rate metric {$metric->value}.",
            ),
        };
    }

    /**
     * Whether adding one more of this metric would exceed the organization's limit.
     */
    public function wouldExceed(Organization $organization, QuotaMetric $metric): bool
    {
        $limit = $this->quotas->limitFor($organization, $metric);

        if ($limit === null) {
            return false;
        }

        return $this->count($organization, $metric) >= $limit;
    }

    /**
     * Readable refusal for a form validation error, naming the limit.
     */
    public function message(Organization $organization, QuotaMetric $metric): string
    {
        $limit = $this->quotas->limitFor($organization, $metric) ?? 0;

        return match ($metric) {
            QuotaMetric::ActiveBroadcasts => "This organization may have at most {$limit} active ".str('broadcast')->plural($limit).'.',
            QuotaMetric::Sources => "This organization may have at most {$limit} ".str('source')->plural($limit).'.',
            QuotaMetric::Destinations => "This organization may have at most {$limit} ".str('destination')->plural($limit).'.',
            QuotaMetric::Members => "This organization may have at most {$limit} ".str('member')->plural($limit).' (including pending invitations).',
            QuotaMetric::SourceFetches, QuotaMetric::Deliveries => throw new InvalidArgumentException(
                "ResourceQuotas::message does not apply to rate metric {$metric->value}.",
            ),
        };
    }
}
