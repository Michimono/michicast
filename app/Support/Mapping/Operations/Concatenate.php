<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class Concatenate implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'concatenate';
    }

    public function label(): string
    {
        return 'Concatenate';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $from = $this->sourceRef($config, 'value')->path;

        return $this->fieldLabel($sourceSchema, $from).' → '.$this->fieldLabel($destinationSchema, $this->targetPath($config));
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $from = $this->sourceRef($config, 'value');
        $to = $this->targetPath($config);

        if ($from->path === '') {
            $errors[] = new MappingError('', 'config', 'Choose a value to append.');
        } elseif ($this->schemaField($sourceSchema, $from->path) === null) {
            $errors[] = new MappingError($from->path, 'missing', $this->fieldLabel($sourceSchema, $from->path).' is no longer available from the source.');
        } else {
            $sourceField = $this->schemaField($sourceSchema, $from->path);
            if ($sourceField->type === FieldType::Array) {
                $errors[] = new MappingError(
                    $from->path,
                    'type',
                    $sourceField->label.' cannot be mapped directly to text. Join items to use this array as text.',
                );
            }
        }

        if ($err = $this->requireTarget($to)) {
            $errors[] = $err;
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $from = $this->sourceRef($config, 'value');
        $to = $this->targetPath($config);
        $append = $this->coerceToText($this->readSource($sources, $from));
        $current = $this->coerceToText(FieldPath::get($state, $to));

        return $this->ok(FieldPath::set($state, $to, $current.$append));
    }
}
