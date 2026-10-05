<?php

namespace App\Models;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use Database\Factories\ContentDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The current state of one content item on a broadcast's destination. Each
 * publish attempt on the route is kept separately as a ContentDeliveryAttempt.
 *
 * Unique per content item, broadcast and destination so a repeated run cannot
 * create a second delivery row for the same route. A content-hash change on
 * the same identity updates the content item in place and does not create a
 * new delivery opportunity: an existing delivered (or pending/skipped) row
 * still blocks republish. Permanent failures also block; transient failures
 * remain reclaimable for a later run.
 *
 * A retry requested from the delivery history only stamps retry_requested_at;
 * the next dispatcher tick republishes the route through the same claim, so a
 * retry updates this row instead of adding a second one.
 *
 * @property DeliveryStatus $status
 * @property DeliveryFailure|null $failure
 * @property Carbon|null $retry_requested_at
 * @property Carbon $attempted_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'content_item_id',
    'broadcast_id',
    'destination_id',
    'broadcast_run_id',
    'status',
    'external_reference',
    'failure',
    'error_detail',
    'retry_after_seconds',
    'retry_requested_at',
    'attempted_at',
    'finished_at',
])]
class ContentDelivery extends Model
{
    /** @use HasFactory<ContentDeliveryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Whether another publish attempt is forbidden for this route.
     *
     * Pending, delivered and skipped always block. Permanent failures block.
     * Transient failures stay reclaimable for a later run.
     */
    public function blocksRepublish(): bool
    {
        if ($this->status === DeliveryStatus::Failed) {
            return $this->failure === DeliveryFailure::Permanent;
        }

        return $this->status->blocksRepublish();
    }

    /**
     * Whether a person may ask for this route to be published again.
     *
     * Only transient failures qualify: a permanent rejection would fail the
     * same way again, and pending, delivered and skipped rows (duplicates
     * included) are not failures at all. The route must still point at the
     * broadcast's current destination, because a retry publishes with the
     * current configuration and must not open a second route for the item.
     */
    public function isRetryable(): bool
    {
        return $this->status === DeliveryStatus::Failed
            && $this->failure === DeliveryFailure::Transient
            && $this->broadcast !== null
            && (int) $this->destination_id === (int) $this->broadcast->destination_id;
    }

    /**
     * Whether a retry is waiting for the next dispatcher tick.
     */
    public function isRetryQueued(): bool
    {
        return $this->retry_requested_at !== null;
    }

    /**
     * Mark this route for the next dispatcher tick. Publishes nothing.
     */
    public function requestRetry(): void
    {
        if ($this->isRetryQueued()) {
            return;
        }

        $this->retry_requested_at = now();
        $this->save();
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * @return BelongsTo<BroadcastRun, $this>
     */
    public function broadcastRun(): BelongsTo
    {
        return $this->belongsTo(BroadcastRun::class);
    }

    /**
     * Every publish attempt on this route, oldest first.
     *
     * @return HasMany<ContentDeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ContentDeliveryAttempt::class)->oldest('attempted_at')->oldest('id');
    }

    /**
     * Failed routes of a broadcast that a retry may republish: transient
     * failures still pointing at the broadcast's current destination.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function retryableOn(Builder $query, Broadcast $broadcast): Builder
    {
        return $query
            ->where('broadcast_id', $broadcast->getKey())
            ->where('destination_id', $broadcast->destination_id)
            ->where('status', DeliveryStatus::Failed)
            ->where('failure', DeliveryFailure::Transient);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'failure' => DeliveryFailure::class,
            'retry_after_seconds' => 'integer',
            'retry_requested_at' => 'datetime',
            'attempted_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
