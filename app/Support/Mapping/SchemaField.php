<?php

namespace App\Support\Mapping;

/**
 * One field inside a content schema. Nested objects use children; arrays use itemSchema.
 */
readonly class SchemaField
{
    /**
     * @param  list<SchemaField>|null  $children
     */
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public ?string $description = null,
        public bool $required = false,
        public ?array $children = null,
        public ?SchemaField $itemSchema = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = FieldType::from((string) ($data['type'] ?? 'text'));
        $children = null;
        $itemSchema = null;

        if (isset($data['children']) && is_array($data['children'])) {
            $children = [];
            foreach ($data['children'] as $child) {
                if (is_array($child)) {
                    $children[] = self::fromArray($child);
                }
            }
        }

        if (isset($data['item_schema']) && is_array($data['item_schema'])) {
            $itemSchema = self::fromArray($data['item_schema']);
        }

        return new self(
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? $data['name'] ?? ''),
            type: $type,
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
            required: (bool) ($data['required'] ?? false),
            children: $children,
            itemSchema: $itemSchema,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        if ($this->children !== null) {
            $payload['children'] = array_map(
                static fn (SchemaField $field): array => $field->toArray(),
                $this->children,
            );
        }

        if ($this->itemSchema !== null) {
            $payload['item_schema'] = $this->itemSchema->toArray();
        }

        return $payload;
    }
}
