<?php

namespace App\Models;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use Database\Factories\ContentDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The outcome of one publish attempt on a delivery route.
 *
 * The delivery row holds the latest state of the route and is reset when a
 * transient failure is reclaimed; attempts are append-only, so the original
 * failure stays readable after a retry succeeds.
 *
 * @property DeliveryStatus $status
 * @property DeliveryFailure|null $failure
 * @property bool $is_retry
 * @property Carbon $attempted_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'content_delivery_id',
    'broadcast_run_id',
    'status',
    'failure',
    'error_detail',
    'external_reference',
    'is_retry',
    'attempted_at',
    'finished_at',
])]
class ContentDeliveryAttempt extends Model
{
    /** @use HasFactory<ContentDeliveryAttemptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ContentDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ContentDelivery::class, 'content_delivery_id');
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
            'is_retry' => 'boolean',
            'attempted_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
