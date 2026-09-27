<?php

namespace App\Support\Mapping;

/**
 * Structured failure from mapping evaluation or destination schema validation.
 */
readonly class MappingError
{
    public function __construct(
        public string $field,
        public string $type,
        public string $message,
        public ?string $element = null,
    ) {}

    /**
     * @return array{field: string, type: string, message: string, element: string|null}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'type' => $this->type,
            'message' => $this->message,
            'element' => $this->element,
        ];
    }
}
