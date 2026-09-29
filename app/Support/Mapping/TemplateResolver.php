<?php

namespace App\Support\Mapping;

/**
 * Restricted {{ path }} template resolver. Not Blade and never eval —
 * placeholders are substituted from a mapping context only.
 */
final class TemplateResolver
{
    /**
     * Match {{path}} or {{ path }} with dotted segments.
     */
    private const TOKEN_PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)\s*\}\}/u';

    /**
     * @param  array<string, mixed>  $context
     * @return array{value: mixed, errors: list<MappingError>, missing: list<string>}
     */
    public function resolve(string $template, array $context): array
    {
        $errors = [];
        $missing = [];

        if ($this->isSingleToken($template)) {
            preg_match(self::TOKEN_PATTERN, trim($template), $match);
            $path = $match[1];

            if (! FieldPath::has($context, $path)) {
                $missing[] = $path;
                $errors[] = new MappingError(
                    field: $path,
                    type: 'missing',
                    message: '{{'.$path.'}} is not available in the mapping context.',
                );

                return ['value' => '', 'errors' => $errors, 'missing' => $missing];
            }

            return [
                'value' => FieldPath::get($context, $path),
                'errors' => [],
                'missing' => [],
            ];
        }

        $value = preg_replace_callback(
            self::TOKEN_PATTERN,
            function (array $match) use ($context, &$errors, &$missing): string {
                $path = $match[1];

                if (! FieldPath::has($context, $path)) {
                    if (! in_array($path, $missing, true)) {
                        $missing[] = $path;
                        $errors[] = new MappingError(
                            field: $path,
                            type: 'missing',
                            message: '{{'.$path.'}} is not available in the mapping context.',
                        );
                    }

                    return '';
                }

                return $this->stringify(FieldPath::get($context, $path));
            },
            $template,
        ) ?? $template;

        return [
            'value' => $value,
            'errors' => $errors,
            'missing' => $missing,
        ];
    }

    /**
     * @return list<string>
     */
    public function referencedPaths(string $template): array
    {
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);

        $paths = [];

        foreach ($matches[1] as $path) {
            if (! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function isSingleToken(string $template): bool
    {
        return (bool) preg_match('/^\s*\{\{\s*[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\s*\}\}\s*$/u', $template);
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
