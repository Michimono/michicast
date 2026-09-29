<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;

final class AddText implements MappingOperation
{
    use ReadsStepConfig;

    public function id(): string
    {
        return 'add_text';
    }

    public function label(): string
    {
        return 'Add text';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $text = $this->stringConfig($config, 'text');
        $preview = str_replace(["\r\n", "\n", "\r"], '↵', $text);
        if (mb_strlen($preview) > 40) {
            $preview = mb_substr($preview, 0, 37).'…';
        }

        return '"'.$preview.'" → '.$this->fieldLabel($destinationSchema, $this->targetPath($config));
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $to = $this->targetPath($config);

        if ($err = $this->requireTarget($to)) {
            $errors[] = $err;
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $to = $this->targetPath($config);
        $text = $this->stringConfig($config, 'text');
        $current = $this->coerceToText(FieldPath::get($state, $to));

        return $this->ok(FieldPath::set($state, $to, $current.$text));
    }
}
