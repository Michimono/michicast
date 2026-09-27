<?php

namespace App\Support\Mapping;

/**
 * Points at a field, optionally scoped to a source or destination context.
 *
 * V1 UI is single-source / single-destination; the context key keeps the door
 * open for multiple partners later without changing persistence shape.
 */
readonly class FieldReference
{
    public function __construct(
        public string $path,
        public string $context = 'default',
    ) {}

    /**
     * @param  array<string, mixed>|string  $value
     */
    public static function from(array|string $value, string $defaultContext = 'default'): self
    {
        if (is_string($value)) {
            return new self(path: $value, context: $defaultContext);
        }

        $path = (string) ($value['path'] ?? $value['field'] ?? '');
        $context = (string) ($value['context'] ?? $value['source'] ?? $defaultContext);

        return new self(path: $path, context: $context !== '' ? $context : $defaultContext);
    }

    /**
     * @return array{path: string, context: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'context' => $this->context,
        ];
    }
}
