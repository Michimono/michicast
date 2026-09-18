<?php

namespace Database\Factories;

use App\Models\BroadcastRun;
use App\Models\BroadcastRunSkip;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BroadcastRunSkip>
 */
class BroadcastRunSkipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcast_run_id' => BroadcastRun::factory(),
            'content_item_id' => ContentItem::factory(),
            'reason' => 'Already delivered or pending for this destination.',
        ];
    }
}
