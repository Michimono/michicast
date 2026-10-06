<?php

namespace App\Enums;

enum AdminAction: string
{
    case SignedIn = 'signed_in';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in',
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
