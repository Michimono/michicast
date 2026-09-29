<?php

namespace App\Support\Mapping;

use Illuminate\Support\Str;

/**
 * One ordered instruction in a mapping pipeline.
 */
readonly class MappingStep
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $id,
        public string $operation,
        public int $order,
        public array $config = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?int $order = null): self
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || $id === '') {
            $id = (string) Str::uuid();
        }

        return new self(
            id: $id,
            operation: (string) ($data['operation'] ?? $data['op'] ?? ''),
            order: $order ?? (int) ($data['order'] ?? 0),
            config: is_array($data['config'] ?? null) ? $data['config'] : [],
        );
    }

    /**
     * @return array{id: string, operation: string, order: int, config: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'order' => $this->order,
            'config' => $this->config,
        ];
    }
}
