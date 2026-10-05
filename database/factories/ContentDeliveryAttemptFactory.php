<?php

namespace Database\Factories;

use App\Enums\DeliveryFailure;
use App\Enums\DeliveryStatus;
use App\Models\ContentDelivery;
use App\Models\ContentDeliveryAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentDeliveryAttempt>
 */
class ContentDeliveryAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_delivery_id' => ContentDelivery::factory(),
            'broadcast_run_id' => null,
            'status' => DeliveryStatus::Delivered,
            'failure' => null,
            'error_detail' => null,
            'external_reference' => 'recording-1',
            'is_retry' => false,
            'attempted_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    public function failed(
        string $detail = 'The destination did not answer in time.',
        DeliveryFailure $failure = DeliveryFailure::Transient,
    ): static {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed,
            'failure' => $failure,
            'error_detail' => $detail,
            'external_reference' => null,
        ]);
    }

    public function retry(): static
    {
        return $this->state(fn (): array => [
            'is_retry' => true,
        ]);
    }
}
