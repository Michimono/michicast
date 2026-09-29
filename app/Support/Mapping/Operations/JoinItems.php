<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class JoinItems implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'join';
    }

    public function label(): string
    {
        return 'Join items';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $from = $this->sourceRef($config, 'field')->path;
        $sep = $this->stringConfig($config, 'separator', ', ');

        return $this->fieldLabel($sourceSchema, $from).' with "'.$sep.'" → '.$this->fieldLabel($destinationSchema, $this->targetPath($config));
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
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

    public function apply(array $state, array $sources, array $config): array
    {
        $from = $this->sourceRef($config, 'field');
        $to = $this->targetPath($config);
        $separator = $this->stringConfig($config, 'separator', ', ');
        $value = $this->readSource($sources, $from);

        if (! is_array($value)) {
            return $this->ok(FieldPath::set($state, $to, $this->coerceToText($value)));
        }

        $parts = [];
        foreach ($value as $item) {
            $parts[] = $this->coerceToText($item);
        }

        return $this->ok(FieldPath::set($state, $to, implode($separator, $parts)));
    }
}
