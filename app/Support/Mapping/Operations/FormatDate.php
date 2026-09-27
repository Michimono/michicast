<?php

namespace App\Support\Mapping\Operations;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldPath;
use App\Support\Mapping\MappingError;
use App\Support\Mapping\Operations\Concerns\ReadsStepConfig;
use Carbon\Carbon;

final class FormatDate implements MappingOperation
{
    use ReadsStepConfig;

    public const PRESETS = [
        'j M Y' => '24 Sep 2026',
        'Y-m-d' => '2026-09-24',
        'M j, Y' => 'Sep 24, 2026',
        'l, F j, Y' => 'Wednesday, September 24, 2026',
        'g:i A' => '3:45 PM',
        'j M Y g:i A' => '24 Sep 2026 3:45 PM',
    ];

    public function id(): string
    {
        return 'format_date';
    }

    public function label(): string
    {
        return 'Format date';
    }

    public function summarize(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): string
    {
        $from = $this->sourceRef($config, 'field')->path;
        $to = $this->targetPath($config);
        $format = $this->stringConfig($config, 'format', 'j M Y');
        $example = self::PRESETS[$format] ?? $format;

        return $this->fieldLabel($sourceSchema, $from).' as '.$example.' → '.$this->fieldLabel($destinationSchema, $to);
    }

    public function validate(array $config, ContentSchema $sourceSchema, ContentSchema $destinationSchema): array
    {
        $errors = [];
        $from = $this->sourceRef($config, 'field');
        $to = $this->targetPath($config);

        if ($from->path === '') {
            $errors[] = new MappingError('', 'config', 'Choose a date field.');
        } elseif ($this->schemaField($sourceSchema, $from->path) === null) {
            $errors[] = new MappingError($from->path, 'missing', $this->fieldLabel($sourceSchema, $from->path).' is no longer available from the source.');
        }

        if ($err = $this->requireTarget($to)) {
            $errors[] = $err;
        }

        $format = $this->stringConfig($config, 'format');
        if ($format === '' || ! array_key_exists($format, self::PRESETS)) {
            $errors[] = new MappingError($to, 'config', 'Choose a date format.');
        }

        return $errors;
    }

    public function apply(array $state, array $sources, array $config): array
    {
        $from = $this->sourceRef($config, 'field');
        $to = $this->targetPath($config);
        $format = $this->stringConfig($config, 'format', 'j M Y');
        $raw = $this->readSource($sources, $from);

        if ($raw === null || $raw === '') {
            return $this->ok(FieldPath::set($state, $to, ''));
        }

        try {
            $formatted = Carbon::parse($raw)->timezone('UTC')->format($format);
        } catch (\Throwable) {
            return $this->fail($state, [new MappingError($to, 'evaluation', 'Could not format that date.')]);
        }

        return $this->ok(FieldPath::set($state, $to, $formatted));
    }
}
