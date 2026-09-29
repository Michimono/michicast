<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;
use App\Support\Mapping\TypeCompatibility;

final class MapField implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'map';
    }

    public function label(): string
    {
        return 'Map field';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $from = $this->sourceRef($config)->path;
        $to = $this->targetPath($config);

        return $this->fieldLabel($sourceSchema, $from).' → '.$this->fieldLabel($destinationSchema, $to);
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $from = $this->sourceRef($config);
        $to = $this->targetPath($config);

        if ($from->path === '') {
            $errors[] = new MappingError('', 'config', 'Choose a source field.');
        } elseif ($this->schemaField($sourceSchema, $from->path) === null) {
            $errors[] = new MappingError($from->path, 'missing', $this->fieldLabel($sourceSchema, $from->path).' is no longer available from the source.');
        }

        if ($err = $this->requireTarget($to)) {
            $errors[] = $err;
        } elseif ($this->schemaField($destinationSchema, $to) === null) {
            $errors[] = new MappingError($to, 'missing', 'Unknown destination field.');
        } else {
            $sourceField = $this->schemaField($sourceSchema, $from->path);
            $destField = $this->schemaField($destinationSchema, $to);

            if ($sourceField !== null) {
                if (TypeCompatibility::arrayNeedsReduction($sourceField->type, $destField->type)) {
                    $errors[] = new MappingError(
                        $to,
                        'type',
                        $sourceField->label.' cannot be mapped directly to text. Join items to use this array as text.',
                    );
                } elseif (! TypeCompatibility::fieldsAreCompatible($sourceField, $destField)
                    && ! ($sourceField->type->isScalar() && $destField->type === FieldType::Text)) {
                    $errors[] = new MappingError(
                        $to,
                        'type',
                        "{$sourceField->label} cannot be mapped directly to {$destField->label}.",
                    );
                }
            }
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $from = $this->sourceRef($config);
        $to = $this->targetPath($config);
        $value = $this->readSource($sources, $from);

        if ($to === '') {
            return $this->fail($state, [new MappingError('', 'config', 'Choose a destination field.')]);
        }

        return $this->ok(FieldPath::set($state, $to, $value));
    }
}
