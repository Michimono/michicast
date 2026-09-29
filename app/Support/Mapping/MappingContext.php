<?php

namespace App\Support\Mapping;

/**
 * Mutable sequential mapping state. Starts as a copy of source fields;
 * each Set field (and legacy op) writes into it; later steps see earlier writes.
 */
final class MappingContext
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $sourceData
     */
    public static function fromSource(array $sourceData): self
    {
        return new self(unserialize(serialize($sourceData)) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $path): mixed
    {
        return FieldPath::get($this->data, $path);
    }

    public function has(string $path): bool
    {
        return FieldPath::has($this->data, $path);
    }

    public function set(string $path, mixed $value): void
    {
        $this->data = FieldPath::set($this->data, $path, $value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function replace(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Destination output is only destination-schema fields from the final context.
     *
     * @return array<string, mixed>
     */
    public function project(ContentSchema $destinationSchema): array
    {
        $out = [];

        foreach ($destinationSchema->fields as $field) {
            if ($this->has($field->name)) {
                $out[$field->name] = $this->get($field->name);
            }
        }

        return $out;
    }
}
