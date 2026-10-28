<?php

namespace App\Support\Mapping;

/**
 * Restricted {{ path }} template resolver. Not Blade and never eval —
 * placeholders are substituted from a mapping context only.
 *
 * A placeholder may list fallbacks: {{linked_url || link || "no link"}}
 * resolves to the first term with a value. In multi-line templates a line
 * whose placeholders all came out empty is left out, and the blank lines
 * around it collapse to one, so missing fields don't leave gaps.
 */
final class TemplateResolver
{
    private const PATH = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*';

    private const LITERAL = '"(?:[^"\\\\]|\\\\.)*"';

    private const TERM = '(?:'.self::PATH.'|'.self::LITERAL.')';

    /**
     * Match {{path}}, {{ path }} or {{ a || b || "text" }}.
     */
    private const TOKEN_PATTERN = '/\{\{\s*('.self::TERM.'(?:\s*\|\|\s*'.self::TERM.')*)\s*\}\}/u';

    private const SINGLE_TOKEN_PATTERN = '/^\s*\{\{\s*'.self::TERM.'(?:\s*\|\|\s*'.self::TERM.')*\s*\}\}\s*$/u';

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

            $value = $this->evaluate($match[1], $context, $errors, $missing);

            return ['value' => $value ?? '', 'errors' => $errors, 'missing' => $missing];
        }

        $lines = preg_split('/\r\n|\n|\r/', $template) ?: [$template];
        $multiLine = count($lines) > 1;
        $rendered = [];

        foreach ($lines as $line) {
            $tokens = 0;
            $filled = 0;

            $text = preg_replace_callback(
                self::TOKEN_PATTERN,
                function (array $match) use ($context, &$errors, &$missing, &$tokens, &$filled): string {
                    $tokens++;
                    $value = $this->stringify($this->evaluate($match[1], $context, $errors, $missing));

                    if (trim($value) !== '') {
                        $filled++;
                    }

                    return $value;
                },
                $line,
            ) ?? $line;

            $rendered[] = [
                'text' => $text,
                'dropped' => $multiLine && $tokens > 0 && $filled === 0,
            ];
        }

        return [
            'value' => $multiLine ? $this->joinLines($rendered) : $rendered[0]['text'],
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

        foreach ($matches[1] as $expression) {
            foreach ($this->terms($expression) as $term) {
                if ($term['path'] !== null && ! in_array($term['path'], $paths, true)) {
                    $paths[] = $term['path'];
                }
            }
        }

        return $paths;
    }

    public function isSingleToken(string $template): bool
    {
        return (bool) preg_match(self::SINGLE_TOKEN_PATTERN, $template);
    }

    /**
     * First term with a value. A lone term keeps its raw value even when
     * empty, so {{media}} still yields the (possibly empty) list it points at.
     *
     * @param  array<string, mixed>  $context
     * @param  list<MappingError>  $errors
     * @param  list<string>  $missing
     */
    private function evaluate(string $expression, array $context, array &$errors, array &$missing): mixed
    {
        $terms = $this->terms($expression);
        $result = null;

        foreach ($terms as $term) {
            if ($term['path'] === null) {
                $value = $term['literal'];
            } elseif (! FieldPath::has($context, $term['path'])) {
                if (! in_array($term['path'], $missing, true)) {
                    $missing[] = $term['path'];
                    $errors[] = new MappingError(
                        field: $term['path'],
                        type: 'missing',
                        message: '{{'.$term['path'].'}} is not available in the mapping context.',
                    );
                }

                continue;
            } else {
                $value = FieldPath::get($context, $term['path']);
            }

            if (count($terms) === 1 || ! $this->isEmpty($value)) {
                return $value;
            }

            $result ??= $value;
        }

        return $result;
    }

    /**
     * @return list<array{path: ?string, literal: ?string}>
     */
    private function terms(string $expression): array
    {
        preg_match_all('/'.self::TERM.'/u', $expression, $matches);

        $terms = [];

        foreach ($matches[0] as $term) {
            $terms[] = str_starts_with($term, '"')
                ? ['path' => null, 'literal' => stripcslashes(substr($term, 1, -1))]
                : ['path' => $term, 'literal' => null];
        }

        return $terms;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * Join rendered lines, leaving out dropped ones. A gap that lost a line
     * keeps at most one blank line, and none at the start or end of the text.
     *
     * @param  list<array{text: string, dropped: bool}>  $lines
     */
    private function joinLines(array $lines): string
    {
        $out = [];
        $gap = [];
        $gapDropped = false;

        foreach ($lines as $line) {
            $blank = ! $line['dropped'] && trim($line['text']) === '';

            if ($line['dropped'] || $blank) {
                $gapDropped = $gapDropped || $line['dropped'];

                if ($blank) {
                    $gap[] = $line['text'];
                }

                continue;
            }

            $out = array_merge($out, $this->closeGap($gap, $gapDropped, $out === []));
            $out[] = $line['text'];
            $gap = [];
            $gapDropped = false;
        }

        if (! $gapDropped) {
            $out = array_merge($out, $gap);
        }

        return implode("\n", $out);
    }

    /**
     * @param  list<string>  $gap
     * @return list<string>
     */
    private function closeGap(array $gap, bool $dropped, bool $atStart): array
    {
        if (! $dropped) {
            return $gap;
        }

        if ($atStart || $gap === []) {
            return [];
        }

        return [''];
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
