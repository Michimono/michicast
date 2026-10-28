<?php

namespace App\Support\Mapping;

use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Render\ResourceLimits;
use Keepsuit\Liquid\Template;

/**
 * Renders mapping values as Liquid templates (Shopify's template language).
 * Never Blade and never eval: Liquid only reads the mapping context.
 *
 * Plain {{ field }} placeholders work as before. Liquid adds fallbacks
 * ({{ linked_url | default: link }}), conditions ({% if text %}…{% endif %})
 * and whitespace control ({%- -%}). Empty text and empty lists count as not
 * set, so {% if text %} is false for an empty field.
 */
final class TemplateResolver
{
    private const PATH = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*';

    /**
     * {{ path }} or {{ path | default: other | default: third }}, alone. Kept
     * as the raw value so a media list stays a list.
     */
    private const RAW_PATTERN = '/^\s*\{\{\s*('.self::PATH.'(?:\s*\|\s*default\s*:\s*'.self::PATH.')*)\s*\}\}\s*$/u';

    private const KEYWORDS = ['empty', 'blank', 'nil', 'null', 'true', 'false', 'forloop', 'tablerowloop'];

    private const CACHE_LIMIT = 200;

    private static ?Environment $environment = null;

    /**
     * @var array<string, Template>
     */
    private static array $templates = [];

    /**
     * @param  array<string, mixed>  $context
     * @return array{value: mixed, errors: list<MappingError>, missing: list<string>}
     */
    public function resolve(string $template, array $context): array
    {
        $missing = [];

        foreach ($this->referencedPaths($template) as $path) {
            if (! FieldPath::has($context, $path)) {
                $missing[] = $path;
            }
        }

        $errors = array_map(
            static fn (string $path): MappingError => new MappingError(
                field: $path,
                type: 'missing',
                message: '{{'.$path.'}} is not available in the mapping context.',
            ),
            $missing,
        );

        if (preg_match(self::RAW_PATTERN, $template, $match)) {
            return [
                'value' => $this->rawValue($match[1], $context),
                'errors' => $errors,
                'missing' => $missing,
            ];
        }

        try {
            $value = $this->template($template)->render(
                self::environment()->newRenderContext(data: $this->liquidData($context)),
            );
        } catch (\Throwable $exception) {
            $errors[] = new MappingError(
                field: '',
                type: 'syntax',
                message: 'The value could not be rendered: '.$exception->getMessage(),
            );
            $value = '';
        }

        return ['value' => $value, 'errors' => $errors, 'missing' => $missing];
    }

    /**
     * Syntax problem in a template (including an unknown filter), or null when it is valid.
     */
    public function syntaxError(string $template): ?string
    {
        try {
            // Unknown filters only fail at render time, so render once with no data.
            $this->template($template)->render(self::environment()->newRenderContext(data: []));
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Context fields a template reads: output tags, default: fallbacks and
     * if / elsif / unless conditions. Names a template defines itself (for,
     * assign, capture) and Liquid keywords are left out.
     *
     * @return list<string>
     */
    public function referencedPaths(string $template): array
    {
        $local = self::KEYWORDS;

        if (preg_match_all('/\{%-?\s*(?:for|assign|capture)\s+([A-Za-z_][A-Za-z0-9_]*)/u', $template, $defined)) {
            $local = [...$local, ...$defined[1]];
        }

        $candidates = [];

        preg_match_all('/\{\{-?\s*('.self::PATH.')/u', $template, $outputs);
        preg_match_all('/default\s*:\s*('.self::PATH.')/u', $template, $fallbacks);
        preg_match_all('/\{%-?\s*(?:if|elsif|unless)\s+('.self::PATH.')/u', $template, $conditions);

        foreach ([...$outputs[1], ...$fallbacks[1], ...$conditions[1]] as $path) {
            $root = explode('.', $path)[0];

            if (! in_array($root, $local, true) && ! in_array($path, $candidates, true)) {
                $candidates[] = $path;
            }
        }

        return $candidates;
    }

    public function isSingleToken(string $template): bool
    {
        return (bool) preg_match(self::RAW_PATTERN, $template);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function rawValue(string $expression, array $context): mixed
    {
        $paths = preg_split('/\s*\|\s*default\s*:\s*/u', trim($expression)) ?: [];
        $result = null;
        $found = false;

        foreach ($paths as $path) {
            if (! FieldPath::has($context, $path)) {
                continue;
            }

            $value = FieldPath::get($context, $path);

            if (count($paths) === 1 || ! $this->isEmpty($value)) {
                return $value;
            }

            if (! $found) {
                $result = $value;
                $found = true;
            }
        }

        return $found ? $result : '';
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false || $value === []) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * Liquid treats "" and [] as true. Turn them into nil so {% if field %}
     * and | default: mean "has a value", and render dates and booleans the way
     * mappings always have.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function liquidData(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $value === [] ? null : $this->liquidData($value);
            } elseif (is_string($value) && trim($value) === '') {
                $value = null;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('c');
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : null;
            } elseif (is_object($value)) {
                $value = null;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    private function template(string $source): Template
    {
        $key = hash('xxh128', $source);

        if (isset(self::$templates[$key])) {
            return self::$templates[$key];
        }

        if (count(self::$templates) >= self::CACHE_LIMIT) {
            self::$templates = [];
        }

        return self::$templates[$key] = self::environment()->parseString($source);
    }

    private static function environment(): Environment
    {
        return self::$environment ??= EnvironmentFactory::new()
            ->setRethrowErrors(true)
            ->setStrictFilters(true)
            ->setLazyParsing(false)
            ->setResourceLimits(new ResourceLimits(
                renderLengthLimit: 50_000,
                renderScoreLimit: 10_000,
                assignScoreLimit: 2_000,
            ))
            ->build();
    }
}
