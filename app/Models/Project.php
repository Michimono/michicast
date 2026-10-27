<?php

namespace App\Models;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\QuotaMetric;
use App\Exceptions\InvalidPipelineGraphException;
use App\Exceptions\InvalidProjectTransitionException;
use App\Support\NextRunCalculator;
use App\Support\Pipeline\PipelineGraph;
use App\Support\ResourceQuotas;
use App\Support\SourceSchedules;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property ProjectKind $kind
 * @property array<string, mixed>|null $graph
 * @property ProjectStatus $status
 * @property ProjectStatus|null $archived_from
 * @property array{frequency: string, time?: string, weekdays?: list<int>, hours?: int}|null $schedule
 * @property string|null $timezone
 * @property Carbon|null $next_run_at
 * @property Carbon|null $claimed_at
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 */
#[Fillable(['name', 'slug', 'description', 'kind'])]
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
        'kind' => 'simple',
        'status' => 'draft',
        'consecutive_failures' => 0,
    ];

    /**
     * Whether routes come from the pipeline canvas rather than the overview.
     */
    public function isVisual(): bool
    {
        return $this->kind === ProjectKind::Visual;
    }

    /**
     * The saved pipeline document. Stored graphs were parsed on the way in,
     * so one that fails to parse now is treated as empty rather than fatal.
     */
    public function pipelineGraph(): PipelineGraph
    {
        if (! is_array($this->graph)) {
            return PipelineGraph::empty();
        }

        try {
            return PipelineGraph::fromArray($this->graph);
        } catch (InvalidPipelineGraphException) {
            return PipelineGraph::empty();
        }
    }

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
        return $this->status === ProjectStatus::Active
            && ! $this->organization->isDisabled();
    }

    public function canMarkDue(): bool
    {
        return $this->status === ProjectStatus::Active;
    }

    public function canActivate(): bool
    {
        return $this->status === ProjectStatus::Draft && $this->hasRequiredBroadcasts();
    }

    /**
     * Why Play is blocked for a draft, as a brief next step for the user, or null
     * when activation is allowed / not a draft. Ordered most actionable first.
     */
    public function activationBlockReason(): ?string
    {
        if ($this->status !== ProjectStatus::Draft || $this->hasRequiredBroadcasts()) {
            return null;
        }

        $hasSource = $this->sources()->notArchived()->exists();
        $hasDestination = $this->destinations()->notArchived()->exists();

        if (! $hasSource && ! $hasDestination) {
            return 'Add a source and a destination to play this broadcast.';
        }

        if (! $hasSource) {
            return 'Add a source to play this broadcast.';
        }

        if (! $hasDestination) {
            return 'Add a destination to play this broadcast.';
        }

        $pipelines = $this->broadcasts()->notArchived();

        if (! $pipelines->exists()) {
            return $this->isVisual()
                ? 'Connect a source to a destination on the canvas to play this broadcast.'
                : 'Connect the source to a destination to play this broadcast.';
        }

        if ($this->broadcasts()->notArchived()->whereNotNull('failure_pause_reason')->exists()) {
            return 'Paused after repeated failures. Fix the pipeline and re-enable it to play.';
        }

        if (app(ResourceQuotas::class)->wouldExceed($this->organization, QuotaMetric::ActiveBroadcasts)) {
            return 'Active broadcast limit reached. Pause or archive another broadcast to play this one.';
        }

        return 'Enable the pipeline to play this broadcast.';
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
     * Queue this project for the next dispatcher tick. Does not run it.
     */
    public function markDue(): void
    {
        if (! $this->canMarkDue()) {
            throw new InvalidProjectTransitionException('Only an active project can be marked due.');
        }

        if ($this->claimed_at !== null) {
            return;
        }

        if ($this->next_run_at !== null && $this->next_run_at->lte(now())) {
            return;
        }

        $this->next_run_at = now();
        $this->save();
    }

    /**
     * Whether a runnable broadcast has a delivery retry that was requested at
     * or after $since and is still waiting. The dispatcher uses this to keep
     * the project due when a retry arrived while a tick held the claim.
     */
    public function hasRetriesRequestedSince(CarbonInterface $since): bool
    {
        return ContentDelivery::query()
            ->where('retry_requested_at', '>=', $since)
            ->whereIn('broadcast_id', $this->broadcasts()->runnable()->select('id'))
            ->exists();
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

    /**
     * Simple broadcasts run on their own schedule. Visual broadcasts run each
     * source on its own (falling back to this one), so this reschedules every
     * live source and takes the earliest.
     */
    public function refreshNextRunAt(?CarbonInterface $from = null): void
    {
        if ($this->isVisual()) {
            $this->next_run_at = app(SourceSchedules::class)->refresh($this, $from ?? now(), true);

            return;
        }

        $next = (new NextRunCalculator)->next(
            $this->schedule,
            $this->effectiveTimezone(),
            $from ?? now(),
        );

        $this->next_run_at = $next === null ? null : Carbon::instance($next);
    }

    /**
     * After a visual broadcast's routes or a source's schedule changed: give
     * new live sources a next run, recompute the listed ones, and move the
     * broadcast's next run to the earliest. A run that is already due or in
     * flight is left alone; it recomputes when it finishes.
     *
     * @param  list<int>  $reschedule
     */
    public function syncSourceSchedules(array $reschedule = []): void
    {
        if (! $this->isVisual()) {
            return;
        }

        $next = app(SourceSchedules::class)->refresh($this, now(), $reschedule);
        $pending = $this->claimed_at !== null
            || ($this->next_run_at !== null && $this->next_run_at->lte(now()));

        if (! $pending) {
            $this->next_run_at = $next;
        }

        $this->save();
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

    public function recordSuccess(): void
    {
        $this->consecutive_failures = 0;
        $this->last_success_at = now();
        $this->save();
    }

    public function recordFailure(): void
    {
        $this->consecutive_failures++;
        $this->last_failure_at = now();
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
     * @return HasMany<ContentItem, $this>
     */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return HasMany<Destination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    /**
     * @return HasMany<Broadcast, $this>
     */
    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }

    /**
     * The project's product pipeline: the oldest non-archived broadcast.
     * UX assumes one; additional broadcasts may exist for legacy data.
     */
    public function primaryBroadcast(): ?Broadcast
    {
        return $this->broadcasts()->notArchived()->oldest('id')->first();
    }

    /**
     * Soft single-broadcast product rule: when a source and destination exist
     * and there is not yet a pipeline broadcast, create one named for the project.
     * Visual broadcasts get their routes from the graph instead, never by default.
     */
    public function ensureDefaultBroadcast(): ?Broadcast
    {
        $existing = $this->primaryBroadcast();

        if ($existing !== null || $this->isVisual()) {
            return $existing;
        }

        $source = $this->sources()->notArchived()->oldest('id')->first();
        $destination = $this->destinations()->notArchived()->oldest('id')->first();

        if ($source === null || $destination === null) {
            return null;
        }

        // Stay under active_broadcasts: create disabled when the org is already at the cap.
        $quotas = app(ResourceQuotas::class);
        $enabled = ! $quotas->wouldExceed($this->organization, QuotaMetric::ActiveBroadcasts);

        return $this->broadcasts()->create([
            'name' => $this->name,
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => $enabled,
        ]);
    }

    /**
     * Most recent run by start time, for workspace cards and dashboards.
     *
     * @return HasOne<ProjectRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ProjectRun::class)->latestOfMany('started_at');
    }

    /**
     * @return HasMany<ProjectRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ProjectRun::class);
    }

    /**
     * Activation needs at least one enabled, unarchived broadcast.
     */
    protected function hasRequiredBroadcasts(): bool
    {
        return $this->broadcasts()->runnable()->exists();
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
    protected function archived(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Archived);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function dispatchable(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Active)
            ->whereHas('organization', fn (Builder $query) => $query
                ->whereNull('suspended_at')
                ->whereNotNull('approved_at'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'graph' => 'array',
            'status' => ProjectStatus::class,
            'archived_from' => ProjectStatus::class,
            'schedule' => 'array',
            'next_run_at' => 'datetime',
            'claimed_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
