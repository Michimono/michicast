<?php

namespace App\Support\Mapping\Operations;

use App\Support\BroadcastTransforms\BroadcastTransformRule;
use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

/**
 * Changes one field already in the mapping context: strip HTML, truncate,
 * or replace. Visual transforms compile to this so a rule can run before
 * or after a Set field.
 */
final class AdjustField implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'adjust_field';
    }

    public function label(): string
    {
        return 'Adjust field';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $field = $this->fieldName($config);
        $label = $this->fieldLabel($destinationSchema, $field);

        if ($label === $field) {
            $label = $this->fieldLabel($sourceSchema, $field);
        }

        return $label.' · '.$this->stringConfig($config, 'op');
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $field = $this->fieldName($config);

        if ($field === '') {
            $errors[] = new MappingError('', 'config', 'Choose a field.');
        }

        if (BroadcastTransformRule::fromArray(['type' => $this->stringConfig($config, 'op')]) === null) {
            $errors[] = new MappingError($field, 'config', 'Choose an operation.');
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $field = $this->fieldName($config);
        $rule = BroadcastTransformRule::fromArray([
            'type' => $this->stringConfig($config, 'op'),
            'value' => $config['value'] ?? null,
            'length' => $config['length'] ?? null,
            'pattern' => $config['pattern'] ?? null,
            'replacement' => $config['replacement'] ?? null,
        ]);

        if ($field === '' || $rule === null) {
            return $this->fail($state, [new MappingError($field, 'config', 'Choose a field and an operation.')]);
        }

        $current = $this->coerceToText(FieldPath::get($state, $field));

        return $this->ok(FieldPath::set($state, $field, $rule->apply($current)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fieldName(array $config): string
    {
        $raw = $config['field'] ?? '';

        return is_string($raw) ? $raw : '';
    }
}
