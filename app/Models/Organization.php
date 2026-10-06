<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

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
     * The role this user holds here, or null when they are not a member.
     */
    public function roleFor(User $user): ?Role
    {
        return $this->members()->firstWhere('user_id', $user->getKey())?->role;
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
}
