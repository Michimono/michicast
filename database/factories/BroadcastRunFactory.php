<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\ProjectRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BroadcastRun>
 */
class BroadcastRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_run_id' => ProjectRun::factory(),
            'broadcast_id' => Broadcast::factory(),
            'status' => RunStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'items_found' => 0,
            'items_new' => 0,
            'items_skipped' => 0,
            'items_delivered' => 0,
            'items_failed' => 0,
            'error_detail' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Running,
            'finished_at' => null,
        ]);
    }

    public function failed(?string $detail = 'The broadcast failed.'): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Failed,
            'finished_at' => now(),
            'error_detail' => $detail,
        ]);
    }
}
