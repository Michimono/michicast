<?php

namespace App\Support\BroadcastFilters;

/**
 * Validates and runs user-supplied patterns with tight PCRE limits so a
 * pathological expression cannot hang a project run.
 */
final class BoundedRegex
{
    private const BACKTRACK_LIMIT = 100_000;

    private const RECURSION_LIMIT = 100;

    public static function isValid(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        return self::withLimits(function () use ($pattern): bool {
            return @preg_match(self::delimited($pattern), '') !== false;
        });
    }

    public static function matches(string $pattern, string $subject): bool
    {
        return self::withLimits(function () use ($pattern, $subject): bool {
            $result = @preg_match(self::delimited($pattern), $subject);

            return $result === 1;
        });
    }

    public static function replace(string $pattern, string $replacement, string $subject): string
    {
        return self::withLimits(function () use ($pattern, $replacement, $subject): string {
            $result = @preg_replace(self::delimited($pattern), $replacement, $subject);

            return is_string($result) ? $result : $subject;
        });
    }

    private static function delimited(string $pattern): string
    {
        $delimiter = "\x01";

        return $delimiter.$pattern.$delimiter.'u';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function withLimits(callable $callback): mixed
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');

        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT);

        try {
            return $callback();
        } finally {
            if (is_string($backtrack)) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }

            if (is_string($recursion)) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }
    }
}
