<?php

namespace App\Models;

use App\Exceptions\InvalidBroadcastTransitionException;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One route from a source to a destination inside a project. Filter JSON is
 * a list of closed rules evaluated with AND; transform JSON holds a template
 * and optional formatting rules.
 *
 * @property array{rules?: list<array<string, mixed>>}|null $filter
 * @property array{template?: string, rules?: list<array<string, mixed>>}|null $transform
 * @property bool $enabled
 * @property int $consecutive_failures
 * @property string|null $failure_pause_reason
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'source_id', 'destination_id', 'filter', 'transform'])]
class Broadcast extends Model
{
    /** @use HasFactory<BroadcastFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'consecutive_failures' => 0,
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether execution should consider this broadcast at all.
     */
    public function isRunnable(): bool
    {
        return $this->enabled && ! $this->isArchived();
    }

    public function canEnable(): bool
    {
        return ! $this->enabled && ! $this->isArchived();
    }

    public function canDisable(): bool
    {
        return $this->enabled && ! $this->isArchived();
    }

    public function enable(): void
    {
        if ($this->isArchived()) {
            throw new InvalidBroadcastTransitionException(
                'An archived broadcast cannot be enabled until it is restored.',
            );
        }

        $this->enabled = true;
        $this->failure_pause_reason = null;
        $this->consecutive_failures = 0;
        $this->save();
    }

    public function disable(): void
    {
        if ($this->isArchived()) {
            throw new InvalidBroadcastTransitionException(
                'An archived broadcast is already out of use.',
            );
        }

        $this->enabled = false;
        $this->save();
    }

    /**
     * Disable after a streak of failures and keep the reason for the UI.
     */
    public function pauseForFailures(string $reason): void
    {
        if ($this->isArchived() || ! $this->enabled) {
            return;
        }

        $this->enabled = false;
        $this->failure_pause_reason = $reason;
        $this->save();
    }

    public function recordSuccess(): void
    {
        $this->consecutive_failures = 0;
        $this->failure_pause_reason = null;
        $this->save();
    }

    public function recordFailure(string $reason): void
    {
        $this->consecutive_failures++;
        $this->save();

        $threshold = max(1, (int) config('michicast.failure.broadcast_pause_after'));

        if ($this->consecutive_failures >= $threshold) {
            $this->pauseForFailures($reason);
        }
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            throw new InvalidBroadcastTransitionException('This broadcast is already archived.');
        }

        $this->archived_at = now();
        $this->save();
    }

    public function restore(): void
    {
        if (! $this->isArchived()) {
            throw new InvalidBroadcastTransitionException('Only an archived broadcast can be restored.');
        }

        $this->archived_at = null;
        $this->save();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * @return HasMany<ContentDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ContentDelivery::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function notArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function runnable(Builder $query): Builder
    {
        return $query->notArchived()->where('enabled', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'transform' => 'array',
            'enabled' => 'boolean',
            'consecutive_failures' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
