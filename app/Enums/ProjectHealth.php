<?php

namespace App\Enums;

enum ProjectHealth: string
{
    case Healthy = 'healthy';
    case AttentionNeeded = 'attention_needed';
    case NeverRun = 'never_run';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::AttentionNeeded => 'Attention needed',
            self::NeverRun => 'Never run',
        };
    }
}
