<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Whether this role carries at least the authority of another.
     *
     * The order is viewer, editor, admin, owner. Policies ask this instead of
     * listing roles, so a new role only has to be ranked once.
     */
    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * The roles offered in a role picker, keyed by value.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    /**
     * Authority as a number, so roles can be compared rather than listed.
     */
    private function rank(): int
    {
        return match ($this) {
            self::Viewer => 1,
            self::Editor => 2,
            self::Admin => 3,
            self::Owner => 4,
        };
    }
}
