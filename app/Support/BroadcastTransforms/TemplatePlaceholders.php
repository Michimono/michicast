<?php

namespace App\Support\BroadcastTransforms;

/**
 * Closed placeholder vocabulary for broadcast templates. Substitution only —
 * templates never pass through Blade or eval.
 */
final class TemplatePlaceholders
{
    public const DEFAULT_TEMPLATE = "{{title}}\n\n{{body}}";

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'title',
            'body',
            'summary',
            'url',
            'author',
            'published',
            'source',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            'title' => 'Item title',
            'body' => 'Full body text',
            'summary' => 'Shortened body',
            'url' => 'Canonical URL',
            'author' => 'Author name',
            'published' => 'Published date',
            'source' => 'Source name',
        ];
    }

    /**
     * Placeholders referenced by a template, in order of first appearance.
     *
     * @return list<string>
     */
    public static function referencedIn(string $template): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/u', $template, $matches);

        $names = [];

        foreach ($matches[1] as $name) {
            if (! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Unknown placeholder names in a template.
     *
     * @return list<string>
     */
    public static function unknownIn(string $template): array
    {
        return array_values(array_diff(self::referencedIn($template), self::names()));
    }
}
