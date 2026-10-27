<?php

namespace App\Support\Mapping;

use App\Models\ContentItem;

/**
 * Turns a ContentItem into the structured source bag the mapping engine reads.
 */
final class ContentItemSourceData
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ContentItem $item): array
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $categories = $metadata['categories'] ?? $metadata['category'] ?? [];

        if (is_string($categories)) {
            $categories = [$categories];
        }

        if (! is_array($categories)) {
            $categories = [];
        }

        // Provider-specific origin fields (e.g. Reddit's text / linked_url /
        // preview_image). The shared fields below always take precedence.
        $extra = [];

        if (is_array($metadata['source_fields'] ?? null)) {
            foreach ($metadata['source_fields'] as $key => $value) {
                if (is_string($key)) {
                    $extra[$key] = $value;
                }
            }
        }

        return [
            ...$extra,
            'title' => $item->title,
            'body' => $item->body,
            'link' => $item->canonical_url,
            'published_at' => $item->published_at?->toIso8601String(),
            'author' => $item->author,
            'categories' => array_values(array_filter(
                $categories,
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            )),
            'media' => is_array($item->media) ? $item->media : [],
        ];
    }
}
