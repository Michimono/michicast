<?php

namespace App\Support\Mapping\Operations\Concerns;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\FieldReference;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\SchemaField;

trait ReadsStepConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function stringConfig(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function intConfig(array $config, string $key, ?int $default = null): ?int
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        return is_numeric($config[$key]) ? (int) $config[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function sourceRef(array $config, string $key = 'from'): FieldReference
    {
        $raw = $config[$key] ?? $config['value'] ?? $config['field'] ?? '';

        return FieldReference::from($raw, 'default');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function targetPath(array $config, string $key = 'to'): string
    {
        $raw = $config[$key] ?? $config['target'] ?? $config['in'] ?? '';

        if (is_array($raw)) {
            return (string) ($raw['path'] ?? '');
        }

        return is_string($raw) ? $raw : '';
    }

    /**
     * @param  array<string, array<string, mixed>>  $sources
     */
    protected function readSource(array $sources, FieldReference $ref): mixed
    {
        $bag = $sources[$ref->context] ?? $sources['default'] ?? [];

        return FieldPath::get($bag, $ref->path);
    }

    protected function fieldLabel(ContentSchema $schema, string $path): string
    {
        $field = $schema->fieldAtPath($path) ?? $schema->field($path);

        return $field === null ? $path : $field->label;
    }

    protected function requireTarget(string $target, string $label = 'destination field'): ?MappingError
    {
        if ($target === '') {
            return new MappingError(field: '', type: 'config', message: "Choose a {$label}.");
        }

        return null;
    }

    protected function coerceToText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array{state: array<string, mixed>, errors: list<MappingError>}
     */
    protected function ok(array $state): array
    {
        return ['state' => $state, 'errors' => []];
    }

    /**
     * @param  list<MappingError>  $errors
     * @return array{state: array<string, mixed>, errors: list<MappingError>}
     */
    protected function fail(array $state, array $errors): array
    {
        return ['state' => $state, 'errors' => $errors];
    }

    protected function schemaField(ContentSchema $schema, string $path): ?SchemaField
    {
        return $schema->fieldAtPath($path) ?? $schema->field($path);
    }
}
