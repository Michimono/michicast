<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Exceptions\InvalidProjectTransitionException;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property ProjectStatus $status
 * @property ProjectStatus|null $archived_from
 */
#[Fillable(['name', 'slug', 'description'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Leaves room in the slug column for the suffix a collision adds.
     */
    private const SLUG_LENGTH_LIMIT = 240;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * Build a URL-safe slug for a name, unique within this organization.
     */
    public static function uniqueSlugFrom(string $name, Organization $organization): string
    {
        $base = rtrim(Str::limit(Str::slug($name), self::SLUG_LENGTH_LIMIT, ''), '-');

        if ($base === '') {
            $base = 'project';
        }

        $slug = $base;
        $suffix = 1;

        while ($organization->projects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $slug;
    }

    /**
     * Only active projects are picked up by the dispatcher.
     */
    public function isDispatchable(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function canActivate(): bool
    {
        return $this->status === ProjectStatus::Draft && $this->hasRequiredBroadcasts();
    }

    public function canPause(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function canResume(): bool
    {
        return $this->status === ProjectStatus::Paused;
    }

    public function canArchive(): bool
    {
        return $this->status !== ProjectStatus::Archived;
    }

    public function canRestore(): bool
    {
        return $this->status === ProjectStatus::Archived;
    }

    public function activate(): void
    {
        if ($this->status === ProjectStatus::Archived) {
            throw new InvalidProjectTransitionException(
                'An archived project cannot be activated without being restored.',
            );
        }

        if ($this->status !== ProjectStatus::Draft) {
            throw new InvalidProjectTransitionException('A project can only be activated from draft.');
        }

        if (! $this->hasRequiredBroadcasts()) {
            throw new InvalidProjectTransitionException(
                'A project cannot be activated until it has at least one enabled broadcast.',
            );
        }

        $this->status = ProjectStatus::Active;
        $this->save();
    }

    public function pause(): void
    {
        if (! $this->canPause()) {
            throw new InvalidProjectTransitionException('A project can only be paused while it is active.');
        }

        $this->status = ProjectStatus::Paused;
        $this->save();
    }

    public function resume(): void
    {
        if (! $this->canResume()) {
            throw new InvalidProjectTransitionException('A project can only be resumed while it is paused.');
        }

        $this->status = ProjectStatus::Active;
        $this->save();
    }

    public function archive(): void
    {
        if (! $this->canArchive()) {
            throw new InvalidProjectTransitionException('An archived project cannot be archived again.');
        }

        $this->archived_from = $this->status;
        $this->status = ProjectStatus::Archived;
        $this->save();
    }

    public function restore(): void
    {
        if (! $this->canRestore()) {
            throw new InvalidProjectTransitionException('Only an archived project can be restored.');
        }

        $this->status = $this->archived_from ?? ProjectStatus::Draft;
        $this->archived_from = null;
        $this->save();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Broadcasts are not modeled yet (M5). Until they exist, activation does
     * not require one.
     */
    protected function hasRequiredBroadcasts(): bool
    {
        return true;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function notArchived(Builder $query): Builder
    {
        return $query->where('status', '!=', ProjectStatus::Archived);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function dispatchable(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'archived_from' => ProjectStatus::class,
        ];
    }
}
