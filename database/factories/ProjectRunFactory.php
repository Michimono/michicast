<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\ProjectRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectRun>
 */
class ProjectRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => RunStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'items_found' => 0,
            'items_new' => 0,
            'items_skipped' => 0,
            'items_delivered' => 0,
            'items_failed' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Running,
            'finished_at' => null,
        ]);
    }

    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Incomplete,
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => RunStatus::Failed,
            'finished_at' => now(),
        ]);
    }
}
