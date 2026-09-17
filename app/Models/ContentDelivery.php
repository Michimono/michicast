<?php

namespace App\Models;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use Database\Factories\ContentDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One publish attempt for a content item on a broadcast's destination.
 *
 * Unique per content item, broadcast and destination so a repeated run cannot
 * create a second delivery row for the same route. A content-hash change on
 * the same identity updates the content item in place and does not create a
 * new delivery opportunity: an existing delivered (or pending/skipped) row
 * still blocks republish.
 *
 * @property DeliveryStatus $status
 * @property DeliveryFailure|null $failure
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'failure' => DeliveryFailure::class,
            'retry_after_seconds' => 'integer',
            'attempted_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
