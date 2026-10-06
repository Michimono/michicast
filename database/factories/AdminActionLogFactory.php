<?php

namespace Database\Factories;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminActionLog>
 */
class AdminActionLogFactory extends Factory
{
    protected $model = AdminActionLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => User::factory(),
            'action' => AdminAction::SignedIn,
            'subject_type' => (new User)->getMorphClass(),
            'subject_id' => fn (array $attributes) => $attributes['admin_id'],
            'reason' => null,
            'changes' => null,
            'ip_address' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }

    public function forAdmin(User $admin): static
    {
        return $this->state(fn (array $attributes): array => [
            'admin_id' => $admin->id,
            'subject_type' => $admin->getMorphClass(),
            'subject_id' => $admin->id,
        ]);
    }

    public function forSubject(User $admin, mixed $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'admin_id' => $admin->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function action(AdminAction $action): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => $action,
        ]);
    }
}
