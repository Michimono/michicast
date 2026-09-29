<?php

namespace App\Support\Mapping;

/**
 * Outcome of evaluating a mapping definition against source data.
 */
readonly class MappingResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<MappingError>  $errors
     */
    public function __construct(
        public array $data,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
