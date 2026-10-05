<?php

namespace Database\Factories;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentDelivery>
 */
class ContentDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'broadcast_id' => Broadcast::factory(),
            'destination_id' => Destination::factory(),
            'broadcast_run_id' => null,
            'status' => DeliveryStatus::Delivered,
            'external_reference' => 'recording-1',
            'failure' => null,
            'error_detail' => null,
            'retry_after_seconds' => null,
            'attempted_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Pending,
            'external_reference' => null,
            'finished_at' => null,
        ]);
    }

    public function failed(string $detail = 'The destination rejected the message.'): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'external_reference' => null,
            'failure' => DeliveryFailure::Permanent,
            'error_detail' => $detail,
            'finished_at' => now(),
        ]);
    }

    public function transientlyFailed(string $detail = 'The destination did not answer in time.'): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'external_reference' => null,
            'failure' => DeliveryFailure::Transient,
            'error_detail' => $detail,
            'finished_at' => now(),
        ]);
    }

    public function skipped(string $detail = 'Duplicate delivery skipped.'): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Skipped,
            'external_reference' => null,
            'failure' => null,
            'error_detail' => $detail,
            'finished_at' => now(),
        ]);
    }
}
