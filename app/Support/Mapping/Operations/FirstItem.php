<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class FirstItem implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'first';
    }

    public function label(): string
    {
        return 'First item';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $from = $this->sourceRef($config, 'field')->path;

        return 'First of '.$this->fieldLabel($sourceSchema, $from).' → '.$this->fieldLabel($destinationSchema, $this->targetPath($config));
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        return $this->validateArrayOp($config, $sourceSchema, $destinationSchema);
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $from = $this->sourceRef($config, 'field');
        $to = $this->targetPath($config);
        $value = $this->readSource($sources, $from);

        if (! is_array($value) || $value === []) {
            return $this->ok(FieldPath::set($state, $to, null));
        }

        return $this->ok(FieldPath::set($state, $to, $value[array_key_first($value)]));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<MappingError>
     */
    private function validateArrayOp(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $from = $this->sourceRef($config, 'field');
        $to = $this->targetPath($config);

        if ($from->path === '') {
            $errors[] = new MappingError('', 'config', 'Choose a list field.');
        } elseif ($this->schemaField($sourceSchema, $from->path) === null) {
            $errors[] = new MappingError($from->path, 'missing', $this->fieldLabel($sourceSchema, $from->path).' is no longer available from the source.');
        }

        if ($err = $this->requireTarget($to)) {
            $errors[] = $err;
        }

        return $errors;
    }
}
