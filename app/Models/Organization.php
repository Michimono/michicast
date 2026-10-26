<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property int|null $suspended_by
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 */
#[Fillable(['name', 'slug', 'timezone', 'plan'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Leaves room in the slug column for the suffix a collision adds.
     */
    private const SLUG_LENGTH_LIMIT = 240;

    /**
     * Build a URL-safe slug for a name, suffixed until it is unused.
     */
    public static function uniqueSlugFrom(string $name): string
    {
        $base = rtrim(Str::limit(Str::slug($name), self::SLUG_LENGTH_LIMIT, ''), '-');

        if ($base === '') {
            $base = 'organization';
        }

        $slug = $base;
        $suffix = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * Whether this organization is currently suspended from writes and dispatch.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Whether this organization is still waiting for a platform admin to
     * approve it. New organizations start here when approval is required.
     */
    public function isPendingApproval(): bool
    {
        return $this->approved_at === null;
    }

    /**
     * Whether writes and dispatch are off, for either reason: suspended by an
     * admin or not approved yet.
     */
    public function isDisabled(): bool
    {
        return $this->isSuspended() || $this->isPendingApproval();
    }

    /**
     * The role this user holds here, or null when they are not a member.
     */
    public function roleFor(User $user): ?Role
    {
        return $this->members()->firstWhere('user_id', $user->getKey())?->role;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<OrganizationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Every source under this organization's projects. Scoped route bindings
     * resolve `sources/{source}` through this, so another organization's
     * source is a 404 rather than a record to authorize.
     *
     * @return HasManyThrough<Source, Project, $this>
     */
    public function sources(): HasManyThrough
    {
        return $this->hasManyThrough(Source::class, Project::class);
    }

    /**
     * Every destination under this organization's projects. Scoped route
     * bindings resolve `destinations/{destination}` through this.
     *
     * @return HasManyThrough<Destination, Project, $this>
     */
    public function destinations(): HasManyThrough
    {
        return $this->hasManyThrough(Destination::class, Project::class);
    }

    /**
     * Every broadcast under this organization's projects. Scoped route
     * bindings resolve `broadcasts/{broadcast}` through this.
     *
     * @return HasManyThrough<Broadcast, Project, $this>
     */
    public function broadcasts(): HasManyThrough
    {
        return $this->hasManyThrough(Broadcast::class, Project::class);
    }

    /**
     * Every project run under this organization's projects.
     *
     * @return HasManyThrough<ProjectRun, Project, $this>
     */
    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(ProjectRun::class, Project::class);
    }

    /**
     * Per-metric limit overrides that replace the plan for this organization.
     *
     * @return HasMany<OrganizationQuotaOverride, $this>
     */
    public function quotaOverrides(): HasMany
    {
        return $this->hasMany(OrganizationQuotaOverride::class);
    }

    /**
     * Recalculate next_run_at for active projects so a long suspension does
     * not cause a catch-up burst when the organization is reinstated.
     */
    public function recalculateActiveProjectSchedules(): void
    {
        $this->projects()
            ->where('status', ProjectStatus::Active)
            ->orderBy('id')
            ->each(function (Project $project): void {
                $project->refreshNextRunAt();
                $project->save();
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
