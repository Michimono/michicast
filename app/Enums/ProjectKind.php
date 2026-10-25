<?php

namespace App\Enums;

/**
 * How a project's routes are defined. Simple broadcasts manage one source and
 * one destination from the overview; visual broadcasts compile their routes
 * from a node graph edited on the pipeline canvas.
 */
enum ProjectKind: string
{
    case Simple = 'simple';
    case Visual = 'visual';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple broadcast',
            self::Visual => 'Visual broadcast',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Visual => 'Visual',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Simple => 'One source, one destination and a mapping between them. The quickest way to get content flowing.',
            self::Visual => 'Draw the pipeline on a canvas: several sources, filters and transforms, fanning out to several destinations.',
        };
    }
}
