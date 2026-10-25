<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * The graph payload is malformed beyond what the editor can show per node:
 * unknown types, dangling or incompatible edges, cycles. Such a graph is
 * refused rather than saved.
 */
class InvalidPipelineGraphException extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors  Dot-keyed messages for the request.
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
