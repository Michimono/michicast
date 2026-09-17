<?php

namespace Database\Factories;

use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(3, true),
            'enabled' => true,
            'filter' => null,
            'transform' => null,
            'archived_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Broadcast $broadcast): void {
            $attributes = $broadcast->getAttributes();
            $projectId = $attributes['project_id'];

            if (! array_key_exists('source_id', $attributes)) {
                $broadcast->setAttribute(
                    'source_id',
                    Source::factory()->create(['project_id' => $projectId])->getKey(),
                );
            }

            if (! array_key_exists('destination_id', $attributes)) {
                $broadcast->setAttribute(
                    'destination_id',
                    Destination::factory()->create(['project_id' => $projectId])->getKey(),
                );
            }
        });
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
}
