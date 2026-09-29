<?php

namespace App\Support\Mapping;

use Throwable;

/**
 * Shared evaluation service for mapping preview and production publishes.
 *
 * Steps mutate an intermediate destination-shaped state; source data is read-only.
 */
class MappingEngine
{
    public function __construct(
        private OperationRegistry $operations = new OperationRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $sourceData
     * @param  array<string, array<string, mixed>>|null  $sources
     */
    public function evaluate(
        ContentSchema $destinationSchema,
        MappingDefinition $definition,
        array $sourceData,
        string $sourceKey = 'default',
        ?array $sources = null,
        ?string $throughStepId = null,
        ?ContentSchema $sourceSchema = null,
    ): MappingResult {
        $sources ??= [$sourceKey => $sourceData];
        if (! isset($sources[$sourceKey])) {
            $sources[$sourceKey] = $sourceData;
        }

        $state = [];
        $errors = [];
        $steps = $definition->stepsUpTo($throughStepId);

        foreach ($steps as $step) {
            if (! $this->operations->has($step->operation)) {
                $errors[] = new MappingError(
                    field: '',
                    type: 'operation',
                    message: 'Unknown mapping step.',
                    element: $step->id,
                );

                continue;
            }

            $operation = $this->operations->get($step->operation);

            if ($sourceSchema !== null) {
                foreach ($operation->validate($step->config, $sourceSchema, $destinationSchema) as $error) {
                    $errors[] = new MappingError(
                        field: $error->field,
                        type: $error->type,
                        message: $error->message,
                        element: $step->id,
                    );
                }
            }

            try {
                $result = $operation->apply($state, $sources, $step->config);
                $state = $result['state'];
                foreach ($result['errors'] as $error) {
                    $errors[] = new MappingError(
                        field: $error->field,
                        type: $error->type,
                        message: $error->message,
                        element: $step->id,
                    );
                }
            } catch (Throwable $exception) {
                $errors[] = new MappingError(
                    field: '',
                    type: 'evaluation',
                    message: 'Could not run this step: '.$exception->getMessage(),
                    element: $step->id,
                );
            }
        }

        if ($throughStepId === null) {
            array_push($errors, ...$this->validateDestination($destinationSchema, $state));
        }

        return new MappingResult($state, $errors);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<MappingError>
     */
    private function validateDestination(ContentSchema $destinationSchema, array $state): array
    {
        $errors = [];

        foreach ($destinationSchema->fields as $field) {
            $value = FieldPath::get($state, $field->name);
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                if ($field->required) {
                    $errors[] = new MappingError(
                        field: $field->name,
                        type: 'required',
                        message: "{$field->label} is required.",
                    );
                }

                continue;
            }

            $typeError = match ($field->type) {
                FieldType::Text, FieldType::Url, FieldType::Datetime => is_scalar($value) || $value instanceof \DateTimeInterface
                    ? null
                    : "{$field->label} must be text.",
                FieldType::Number => is_numeric($value) ? null : "{$field->label} must be a number.",
                FieldType::Boolean => is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1'
                    ? null
                    : "{$field->label} must be yes or no.",
                FieldType::Media, FieldType::Array, FieldType::Object => is_array($value)
                    ? null
                    : "{$field->label} must be a list.",
            };

            if ($typeError !== null) {
                $errors[] = new MappingError($field->name, 'type', $typeError);
            }
        }

        return $errors;
    }

    public function operations(): OperationRegistry
    {
        return $this->operations;
    }
}
