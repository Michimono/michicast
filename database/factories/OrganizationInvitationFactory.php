<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
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
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Viewer,
            'token' => OrganizationInvitation::hashToken(OrganizationInvitation::generatePlainToken()),
            'expires_at' => now()->addDays(OrganizationInvitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ];
    }

    public function forEmail(string $email): static
    {
        return $this->state(fn (array $attributes) => ['email' => strtolower($email)]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    public function withPlainToken(string $plain): static
    {
        return $this->state(fn (array $attributes) => [
            'token' => OrganizationInvitation::hashToken($plain),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }
}
