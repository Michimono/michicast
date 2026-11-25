<?php

namespace App\Support;

/**
 * The one shape media takes on a content item and on a prepared message: an
 * ordered list of pictures and videos, each a public http(s) URL.
 *
 * Sources hand in whatever their origin exposes; anything that is not a
 * usable picture or video link is dropped, repeats are removed, and the
 * origin's order is kept. URLs are passed through as they are and never
 * fetched here.
 *
 * @phpstan-type MediaEntry array{
 *     alt: string|null,
 *     height: int|null,
 *     mime_type: string|null,
 *     type: string,
 *     url: string,
 *     width: int|null
 * }
 */
final class MediaList
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /**
     * The most entries one item keeps.
     */
    public const MAX_ENTRIES = 10;

    private const MAX_URL_LENGTH = 2048;

    private const MAX_ALT_LENGTH = 500;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm'];

    /**
     * @return list<MediaEntry>
     */
    public static function normalize(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }

        $entries = [];

        foreach ($media as $candidate) {
            $entry = self::entry($candidate);

            if ($entry === null || isset($entries[$entry['url']])) {
                continue;
            }

            $entries[$entry['url']] = $entry;

            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return array_values($entries);
    }

    /**
     * Whether a list already holds a picture.
     *
     * @param  list<array<string, mixed>>  $media
     */
    public static function hasImage(array $media): bool
    {
        foreach ($media as $entry) {
            if (($entry['type'] ?? null) === self::TYPE_IMAGE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return MediaEntry|null
     */
    private static function entry(mixed $candidate): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $url = self::url($candidate['url'] ?? null);

        if ($url === null) {
            return null;
        }

        $mimeType = self::mimeType($candidate['mime_type'] ?? null);
        $type = self::type($candidate['type'] ?? null, $mimeType, $url);

        if ($type === null) {
            return null;
        }

        return [
            'alt' => self::alt($candidate['alt'] ?? null),
            'height' => self::dimension($candidate['height'] ?? null),
            'mime_type' => $mimeType,
            'type' => $type,
            'url' => $url,
            'width' => self::dimension($candidate['width'] ?? null),
        ];
    }

    private static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_URL_LENGTH) {
            return null;
        }

        if (preg_match('#^https?://[^\s/?\#]+#i', $value) !== 1 || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function mimeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim(explode(';', $value)[0]));

        return $value === '' ? null : $value;
    }

    /**
     * The declared type when it is one we carry, otherwise what the MIME type
     * or the file extension says. Anything else (audio, documents) is not media
     * a destination can attach.
     */
    private static function type(mixed $declared, ?string $mimeType, string $url): ?string
    {
        if (is_string($declared)) {
            $declared = strtolower(trim($declared));

            if ($declared === self::TYPE_IMAGE || $declared === self::TYPE_VIDEO) {
                return $declared;
            }
        }

        if ($mimeType !== null) {
            return match (true) {
                str_starts_with($mimeType, 'image/') => self::TYPE_IMAGE,
                str_starts_with($mimeType, 'video/') => self::TYPE_VIDEO,
                default => null,
            };
        }

        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, self::IMAGE_EXTENSIONS, true) => self::TYPE_IMAGE,
            in_array($extension, self::VIDEO_EXTENSIONS, true) => self::TYPE_VIDEO,
            default => null,
        };
    }

    private static function dimension(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private static function alt(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_ALT_LENGTH);
    }
}
