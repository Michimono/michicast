<?php

namespace App\Models;

use Database\Factories\BroadcastRunSkipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skip that belongs to a broadcast run but could not become a delivery row
 * (for example a duplicate of an already-delivered route).
 */
#[Fillable([
    'broadcast_run_id',
    'content_item_id',
    'reason',
])]
class BroadcastRunSkip extends Model
{
    /** @use HasFactory<BroadcastRunSkipFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<BroadcastRun, $this>
     */
    public function broadcastRun(): BelongsTo
    {
        return $this->belongsTo(BroadcastRun::class);
    }

    /**
     * @return BelongsTo<ContentItem, $this>
     */
    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
