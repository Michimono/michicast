<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => 'fake',
            'enabled' => true,
            'config' => ['feed_url' => fake()->url()],
            'archived_at' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'archived_at' => now(),
        ]);
    }

    public function tested(bool $successful = true, string $message = 'The connection succeeded.'): static
    {
        return $this->state(fn (): array => [
            'last_tested_at' => now(),
            'last_test_successful' => $successful,
            'last_test_message' => $message,
        ]);
    }
}
