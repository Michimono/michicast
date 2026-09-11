<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->words(3, true),
            'slug' => fn (array $attributes): string => Str::slug((string) $attributes['name']).'-'.fake()->unique()->numerify('####'),
            'description' => null,
            'status' => ProjectStatus::Draft,
            'schedule' => null,
            'timezone' => null,
            'next_run_at' => null,
            'claimed_at' => null,
        ];
    }

    public function dailyAt(string $time, ?string $timezone = null): static
    {
        return $this->state(fn (): array => [
            'schedule' => [
                'frequency' => 'daily',
                'time' => $time,
            ],
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  list<int>  $weekdays
     */
    public function weeklyAt(string $time, array $weekdays, ?string $timezone = null): static
    {
        return $this->state(fn (): array => [
            'schedule' => [
                'frequency' => 'weekly',
                'time' => $time,
                'weekdays' => $weekdays,
            ],
            'timezone' => $timezone,
        ]);
    }

    public function everyHours(int $hours, ?string $timezone = null): static
    {
        return $this->state(fn (): array => [
            'schedule' => [
                'frequency' => 'interval',
                'hours' => $hours,
            ],
            'timezone' => $timezone,
        ]);
    }

    public function due(?DateTimeInterface $at = null): static
    {
        return $this->active()->state(fn (): array => [
            'schedule' => [
                'frequency' => 'daily',
                'time' => '09:00',
            ],
            'next_run_at' => $at ?? now()->subMinute(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Active,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Paused,
        ]);
    }

    public function archived(?ProjectStatus $from = ProjectStatus::Draft): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectStatus::Archived,
            'archived_from' => $from,
        ]);
    }
}
