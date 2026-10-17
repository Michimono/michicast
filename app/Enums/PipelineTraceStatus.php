<?php

namespace App\Enums;

/**
 * What a dry run found on one connection of the graph.
 */
enum PipelineTraceStatus: string
{
    /** At least one item crossed the connection. */
    case Flowed = 'flowed';

    /** The route ran but nothing reached this connection. */
    case Empty = 'empty';

    /** The route this connection belongs to was not run (disabled or paused). */
    case Skipped = 'skipped';

    /** The route failed before anything could cross (for example the fetch failed). */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Flowed => 'Items flowed',
            self::Empty => 'Nothing flowed',
            self::Skipped => 'Not run',
            self::Failed => 'Failed',
        };
    }
}
