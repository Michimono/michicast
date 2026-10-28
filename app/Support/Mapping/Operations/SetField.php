<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;
use App\Support\Mapping\TemplateResolver;

/**
 * Primary mapping operation: assign a field from a {{var}} value template.
 * Resolves against the mutable mapping context; last write wins.
 */
final class SetField implements MappingOperation
{
    use ReadsStepConfig;

    public function __construct(
        private TemplateResolver $resolver = new TemplateResolver,
    ) {}

    public function id(): string
    {
        return 'set_field';
    }

    public function label(): string
    {
        return 'Set field';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $field = $this->fieldName($config);
        $value = $this->stringConfig($config, 'value');
        $preview = str_replace(["\r\n", "\n", "\r"], '↵', $value);

        if (mb_strlen($preview) > 48) {
            $preview = mb_substr($preview, 0, 45).'…';
        }

        $label = $this->fieldLabel($destinationSchema, $field);
        if ($label === $field) {
            $label = $this->fieldLabel($sourceSchema, $field);
        }

        return $label.' = "'.$preview.'"';
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $field = $this->fieldName($config);
        $value = $this->stringConfig($config, 'value');

        if ($field === '') {
            $errors[] = new MappingError('', 'config', 'Choose a field.');
        }

        $syntax = $this->resolver->syntaxError($value);

        if ($syntax !== null) {
            $errors[] = new MappingError($field, 'syntax', 'The value is not valid Liquid: '.$syntax);
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $field = $this->fieldName($config);
        $template = array_key_exists('value', $config)
            ? (is_string($config['value']) || is_numeric($config['value']) ? (string) $config['value'] : '')
            : '';

        if ($field === '') {
            return $this->fail($state, [new MappingError('', 'config', 'Choose a field.')]);
        }

        $resolved = $this->resolver->resolve($template, $state);
        $errors = $resolved['errors'];

        // Missing placeholders become empty string and still assign (deterministic).
        // Structured errors are returned so the UI can surface them.
        $state = FieldPath::set($state, $field, $resolved['value']);

        return ['state' => $state, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fieldName(array $config): string
    {
        $raw = $config['field'] ?? $config['to'] ?? $config['target'] ?? '';

        if (is_array($raw)) {
            return (string) ($raw['path'] ?? '');
        }

        return is_string($raw) ? $raw : '';
    }
}
