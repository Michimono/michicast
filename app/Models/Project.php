<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Exceptions\InvalidProjectTransitionException;
use App\Support\NextRunCalculator;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property ProjectStatus $status
 * @property ProjectStatus|null $archived_from
 * @property array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}|null $schedule
 * @property string|null $timezone
 * @property Carbon|null $next_run_at
 * @property Carbon|null $claimed_at
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
        $this->refreshNextRunAt();
        $this->save();
    }

    public function pause(): void
    {
        if (! $this->canPause()) {
            throw new InvalidProjectTransitionException('A project can only be paused while it is active.');
        }

        $this->status = ProjectStatus::Paused;
        $this->refreshNextRunAt();
        $this->save();
    }

    public function resume(): void
    {
        if (! $this->canResume()) {
            throw new InvalidProjectTransitionException('A project can only be resumed while it is paused.');
        }

        $this->status = ProjectStatus::Active;
        $this->refreshNextRunAt();
        $this->save();
    }

    public function archive(): void
    {
        if (! $this->canArchive()) {
            throw new InvalidProjectTransitionException('An archived project cannot be archived again.');
        }

        $this->archived_from = $this->status;
        $this->status = ProjectStatus::Archived;
        $this->refreshNextRunAt();
        $this->save();
    }

    public function restore(): void
    {
        if (! $this->canRestore()) {
            throw new InvalidProjectTransitionException('Only an archived project can be restored.');
        }

        $this->status = $this->archived_from ?? ProjectStatus::Draft;
        $this->archived_from = null;
        $this->refreshNextRunAt();
        $this->save();
    }

    /**
     * The timezone the schedule is resolved against.
     */
    public function effectiveTimezone(): string
    {
        if (is_string($this->timezone) && $this->timezone !== '') {
            return $this->timezone;
        }

        return $this->organization->timezone;
    }

    public function hasTimezoneOverride(): bool
    {
        return is_string($this->timezone) && $this->timezone !== '';
    }

    /**
     * @param  array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}  $schedule
     */
    public function applySchedule(array $schedule, ?string $timezone): void
    {
        $this->schedule = $schedule;
        $this->timezone = $timezone;
        $this->refreshNextRunAt();
        $this->save();
    }

    public function refreshNextRunAt(?CarbonInterface $from = null): void
    {
        $next = (new NextRunCalculator)->next(
            $this->schedule,
            $this->effectiveTimezone(),
            $from ?? now(),
        );

        $this->next_run_at = $next === null ? null : Carbon::instance($next);
    }

    /**
     * Atomically claim this project for a dispatch tick. Returns false when
     * another tick already holds a fresh claim.
     */
    public function claimDueRun(CarbonInterface $now, CarbonInterface $staleBefore): bool
    {
        return static::query()
            ->dispatchable()
            ->whereKey($this->getKey())
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $staleBefore);
            })
            ->update(['claimed_at' => $now]) === 1;
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
            'schedule' => 'array',
            'next_run_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }
}
