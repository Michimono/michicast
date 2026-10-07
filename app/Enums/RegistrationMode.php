<?php

namespace App\Enums;

enum RegistrationMode: string
{
    case Open = 'open';
    case InviteOnly = 'invite_only';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InviteOnly => 'Invite only',
            self::Closed => 'Closed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Anyone can create an account.',
            self::InviteOnly => 'Only emails with a pending, unexpired invitation can create an account.',
            self::Closed => 'Nobody can create an account. Existing users can still log in.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $mode) {
            $options[$mode->value] = $mode->label();
        }

        return $options;
    }
}
