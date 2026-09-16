<?php

namespace App\Enums;

enum DeliveryFailure: string
{
    case Permanent = 'permanent';
    case Transient = 'transient';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Transient => 'Transient',
        };
    }
}
