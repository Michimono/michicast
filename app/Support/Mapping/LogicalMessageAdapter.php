<?php

namespace App\Support\Mapping;

use App\Support\PreparedMessage;

/**
 * Converts a mapped logical destination object into PreparedMessage for publish.
 *
 * Destination providers still own external API formatting (e.g. Telegram Bot API).
 */
final class LogicalMessageAdapter
{
    /**
     * @param  array<string, mixed>  $logical
     */
    public function toPreparedMessage(array $logical): PreparedMessage
    {
        $text = $logical['text'] ?? $logical['body'] ?? '';
        $text = is_scalar($text) ? (string) $text : '';

        $link = $logical['link'] ?? null;
        $link = is_string($link) && trim($link) !== '' ? trim($link) : null;

        $media = $logical['media'] ?? [];
        $media = is_array($media) ? array_values($media) : [];

        return new PreparedMessage(
            body: $text,
            link: $link,
            media: $media,
            fields: $logical,
        );
    }
}
