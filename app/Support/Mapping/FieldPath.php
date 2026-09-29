<?php

namespace App\Support\Mapping;

/**
 * Resolves dotted field paths against an associative data bag.
 */
final class FieldPath
{
    public static function get(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public static function has(array $data, string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function set(array $data, string $path, mixed $value): array
    {
        if ($path === '') {
            return $data;
        }

        $segments = explode('.', $path);
        $ref = &$data;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $ref[$segment] = $value;

                break;
            }

            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }

        return $data;
    }
}
