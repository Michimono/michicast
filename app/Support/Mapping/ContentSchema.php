<?php

namespace App\Support\Mapping;

/**
 * Versionable content schema for a source or destination provider.
 */
readonly class ContentSchema
{
    /**
     * @param  list<SchemaField>  $fields
     */
    public function __construct(
        public int $version,
        public array $fields,
        public string $providerType = '',
        public string $label = '',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fields = [];

        foreach ($data['fields'] ?? [] as $field) {
            if (is_array($field)) {
                $fields[] = SchemaField::fromArray($field);
            }
        }

        return new self(
            version: (int) ($data['version'] ?? 1),
            fields: $fields,
            providerType: (string) ($data['provider_type'] ?? ''),
            label: (string) ($data['label'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'provider_type' => $this->providerType,
            'label' => $this->label,
            'fields' => array_map(
                static fn (SchemaField $field): array => $field->toArray(),
                $this->fields,
            ),
        ];
    }

    public function field(string $name): ?SchemaField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function fieldAtPath(string $path): ?SchemaField
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return null;
        }

        $current = $this->field(array_shift($segments));

        foreach ($segments as $segment) {
            if ($current === null) {
                return null;
            }

            if ($current->type === FieldType::Array && $current->itemSchema !== null) {
                $current = $current->itemSchema;
                if ($segment === '*' || $segment === 'item') {
                    continue;
                }
            }

            if ($current->children === null) {
                return null;
            }

            $next = null;
            foreach ($current->children as $child) {
                if ($child->name === $segment) {
                    $next = $child;
                    break;
                }
            }
            $current = $next;
        }

        return $current;
    }

    /**
     * @return list<SchemaField>
     */
    public function requiredFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (SchemaField $field): bool => $field->required,
        ));
    }

    public function fieldCount(): int
    {
        return count($this->fields);
    }
}
