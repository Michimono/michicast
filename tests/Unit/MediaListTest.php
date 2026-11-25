<?php

namespace Tests\Unit;

use App\Support\MediaList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MediaListTest extends TestCase
{
    public function test_entries_take_one_shape_and_keep_the_origin_order(): void
    {
        $media = MediaList::normalize([
            ['url' => 'https://cdn.example.com/b.mp4', 'type' => 'VIDEO', 'width' => '1280', 'height' => 720],
            ['url' => ' https://cdn.example.com/a.jpg ', 'type' => 'image', 'mime_type' => 'Image/JPEG; charset=binary', 'alt' => '  Cover  '],
        ]);

        $this->assertSame([
            ['alt' => null, 'height' => 720, 'mime_type' => null, 'type' => 'video', 'url' => 'https://cdn.example.com/b.mp4', 'width' => 1280],
            ['alt' => 'Cover', 'height' => null, 'mime_type' => 'image/jpeg', 'type' => 'image', 'url' => 'https://cdn.example.com/a.jpg', 'width' => null],
        ], $media);
    }

    #[DataProvider('inferredTypes')]
    public function test_the_type_comes_from_the_mime_type_or_the_extension_when_it_is_not_declared(array $entry, ?string $expected): void
    {
        $media = MediaList::normalize([$entry]);

        $this->assertSame($expected, $media[0]['type'] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function inferredTypes(): array
    {
        return [
            'image mime type' => [['url' => 'https://cdn.example.com/file', 'mime_type' => 'image/webp'], 'image'],
            'video mime type' => [['url' => 'https://cdn.example.com/file', 'mime_type' => 'video/mp4'], 'video'],
            'image extension' => [['url' => 'https://cdn.example.com/file.PNG?size=large'], 'image'],
            'video extension' => [['url' => 'https://cdn.example.com/file.webm'], 'video'],
            'an unknown declared type falls back to the extension' => [['url' => 'https://cdn.example.com/file.jpg', 'type' => 'photo'], 'image'],
            'audio is not media a destination attaches' => [['url' => 'https://cdn.example.com/episode.mp3', 'mime_type' => 'audio/mpeg'], null],
            'a mime type that is not a picture wins over the extension' => [['url' => 'https://cdn.example.com/file.jpg', 'mime_type' => 'application/pdf'], null],
            'nothing says what it is' => [['url' => 'https://example.com/page'], null],
        ];
    }

    #[DataProvider('unusableAddresses')]
    public function test_an_entry_without_a_public_http_address_is_dropped(mixed $entry): void
    {
        $this->assertSame([], MediaList::normalize([$entry]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableAddresses(): array
    {
        return [
            'inline data' => [['type' => 'image', 'url' => 'data:image/png;base64,iVBORw0KGgo=']],
            'script address' => [['type' => 'image', 'url' => 'javascript:alert(1)']],
            'local file' => [['type' => 'image', 'url' => 'file:///etc/passwd']],
            'relative address' => [['type' => 'image', 'url' => '/images/cover.jpg']],
            'no host' => [['type' => 'image', 'url' => 'https:///cover.jpg']],
            'line break inside' => [['type' => 'image', 'url' => "https://cdn.example.com/a.jpg\nX-Header: 1"]],
            'too long' => [['type' => 'image', 'url' => 'https://cdn.example.com/'.str_repeat('a', 2048).'.jpg']],
            'missing address' => [['type' => 'image']],
            'not an entry' => ['https://cdn.example.com/a.jpg'],
        ];
    }

    public function test_repeats_are_removed_and_the_first_one_stays(): void
    {
        $media = MediaList::normalize([
            ['type' => 'image', 'url' => 'https://cdn.example.com/a.jpg', 'alt' => 'First'],
            ['type' => 'image', 'url' => 'https://cdn.example.com/b.jpg'],
            ['type' => 'image', 'url' => 'https://cdn.example.com/a.jpg', 'alt' => 'Again'],
        ]);

        $this->assertSame(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'], array_column($media, 'url'));
        $this->assertSame('First', $media[0]['alt']);
    }

    public function test_an_item_keeps_at_most_the_cap(): void
    {
        $entries = [];

        for ($index = 1; $index <= MediaList::MAX_ENTRIES + 3; $index++) {
            $entries[] = ['type' => 'image', 'url' => "https://cdn.example.com/{$index}.jpg"];
        }

        $media = MediaList::normalize($entries);

        $this->assertCount(MediaList::MAX_ENTRIES, $media);
        $this->assertSame('https://cdn.example.com/1.jpg', $media[0]['url']);
        $this->assertSame('https://cdn.example.com/'.MediaList::MAX_ENTRIES.'.jpg', $media[MediaList::MAX_ENTRIES - 1]['url']);
    }

    public function test_dimensions_that_are_not_positive_numbers_are_left_out(): void
    {
        $media = MediaList::normalize([
            ['type' => 'image', 'url' => 'https://cdn.example.com/a.jpg', 'width' => '0', 'height' => 'auto'],
        ]);

        $this->assertNull($media[0]['width']);
        $this->assertNull($media[0]['height']);
    }

    public function test_anything_that_is_not_a_list_is_no_media(): void
    {
        $this->assertSame([], MediaList::normalize(null));
        $this->assertSame([], MediaList::normalize('https://cdn.example.com/a.jpg'));
    }
}
