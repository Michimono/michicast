<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class TrimText implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'trim';
    }

    public function label(): string
    {
        return 'Trim text';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $field = $this->targetPath($config, 'field');
        $max = $this->intConfig($config, 'max_length');

        return $this->fieldLabel($destinationSchema, $field).($max !== null ? ' → '.$max.' characters' : '');
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $field = $this->targetPath($config, 'field');
        $max = $this->intConfig($config, 'max_length');

        if ($err = $this->requireTarget($field, 'field to trim')) {
            $errors[] = $err;
        }

        if ($max === null || $max < 1) {
            $errors[] = new MappingError($field, 'config', 'Enter a maximum length of at least 1.');
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $field = $this->targetPath($config, 'field');
        $max = $this->intConfig($config, 'max_length');
        $current = $this->coerceToText(FieldPath::get($state, $field));

        if ($max === null || $max < 1) {
            return $this->fail($state, [new MappingError($field, 'config', 'Enter a maximum length of at least 1.')]);
        }

        if (mb_strlen($current) <= $max) {
            return $this->ok($state);
        }

        return $this->ok(FieldPath::set($state, $field, mb_substr($current, 0, $max)));
    }
}
