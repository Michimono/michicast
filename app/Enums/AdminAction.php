<?php

namespace App\Enums;

enum AdminAction: string
{
    case SignedIn = 'signed_in';
    case Banned = 'banned';
    case Unbanned = 'unbanned';
    case Suspended = 'suspended';
    case Reinstated = 'reinstated';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
            self::Banned => 'Banned',
            self::Unbanned => 'Unbanned',
            self::Suspended => 'Suspended',
            self::Reinstated => 'Reinstated',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $action) {
            $options[$action->value] = $action->label();
        }

        return $options;
    }
}
