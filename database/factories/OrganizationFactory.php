<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'slug' => fn (array $attributes) => Organization::uniqueSlugFrom((string) $attributes['name']),
            'timezone' => 'UTC',
            'plan' => fn () => config('michicast.quotas.default_plan'),
            'approved_at' => now(),
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->withMember($user, Role::Owner);
    }

    public function suspended(?string $reason = 'Suspended for testing'): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
            'suspension_reason' => $reason,
            'suspended_by' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    public function withMember(User $user, Role $role): static
    {
        return $this->afterCreating(function (Organization $organization) use ($user, $role): void {
            $organization->members()->create([
                'user_id' => $user->getKey(),
                'role' => $role,
            ]);
        });
    }
}
