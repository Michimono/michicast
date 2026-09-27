<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class ReplaceText implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'replace';
    }

    public function label(): string
    {
        return 'Replace text';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $find = $this->stringConfig($config, 'find');
        $in = $this->targetPath($config, 'in');

        return '"'.$find.'" in '.$this->fieldLabel($destinationSchema, $in);
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $in = $this->targetPath($config, 'in');

        if ($err = $this->requireTarget($in, 'field to replace in')) {
            $errors[] = $err;
        }

        if ($this->stringConfig($config, 'find') === '') {
            $errors[] = new MappingError($in, 'config', 'Enter the text to find.');
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $in = $this->targetPath($config, 'in');
        $find = $this->stringConfig($config, 'find');
        $replace = $this->stringConfig($config, 'replace');
        $current = $this->coerceToText(FieldPath::get($state, $in));

        if ($find === '') {
            return $this->ok($state);
        }

        return $this->ok(FieldPath::set($state, $in, str_replace($find, $replace, $current)));
    }
}
