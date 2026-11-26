<?php

namespace Tests\Unit;

use App\Support\DestinationMediaSupport;
use App\Support\PreparedMessage;
use PHPUnit\Framework\TestCase;

class DestinationMediaSupportTest extends TestCase
{
    public function test_a_destination_receives_the_first_entries_of_a_type_it_accepts(): void
    {
        $fit = DestinationMediaSupport::of(['image'], 2)->fit($this->media());

        $this->assertSame(
            ['https://cdn.example.com/one.jpg', 'https://cdn.example.com/two.jpg'],
            array_column($fit->attached, 'url'),
        );
        $this->assertSame(2, $fit->leftOff);
        $this->assertSame('2 left off. This destination takes up to 2 images per message.', $fit->note());
    }

    public function test_everything_fits_when_the_destination_takes_it_all(): void
    {
        $fit = DestinationMediaSupport::of(['image', 'video'], 10)->fit($this->media());

        $this->assertCount(4, $fit->attached);
        $this->assertSame('video', $fit->attached[1]['type']);
        $this->assertSame(0, $fit->leftOff);
        $this->assertNull($fit->note());
    }

    public function test_a_text_only_destination_attaches_nothing(): void
    {
        $fit = DestinationMediaSupport::none()->fit($this->media());

        $this->assertSame([], $fit->attached);
        $this->assertSame(4, $fit->leftOff);
        $this->assertSame('Not attached. This destination sends text only.', $fit->note());
    }

    public function test_nothing_mapped_needs_no_note(): void
    {
        $fit = DestinationMediaSupport::none()->fit([]);

        $this->assertSame([], $fit->attached);
        $this->assertSame(0, $fit->leftOff);
        $this->assertNull($fit->note());
    }

    public function test_entries_that_are_not_usable_media_count_as_left_off(): void
    {
        $fit = DestinationMediaSupport::of(['image'], 5)->fit([
            ['type' => 'image', 'url' => 'https://cdn.example.com/one.jpg'],
            'not an entry',
            ['type' => 'image', 'url' => 'data:image/png;base64,iVBORw0KGgo='],
        ]);

        $this->assertCount(1, $fit->attached);
        $this->assertSame(2, $fit->leftOff);
    }

    public function test_a_declaration_without_a_usable_type_or_count_is_text_only(): void
    {
        $this->assertFalse(DestinationMediaSupport::of(['audio'], 3)->acceptsAny());
        $this->assertFalse(DestinationMediaSupport::of(['image'], 0)->acceptsAny());
        $this->assertSame('text only', DestinationMediaSupport::none()->describe());
        $this->assertSame('up to 1 image', DestinationMediaSupport::of(['image'], 1)->describe());
        $this->assertSame('up to 10 images or videos', DestinationMediaSupport::of(['video', 'image'], 10)->describe());
    }

    public function test_fitting_a_message_changes_its_media_and_nothing_else(): void
    {
        $message = new PreparedMessage('Hello', 'https://example.com/post', $this->media(), ['text' => 'Hello']);

        $fitted = $message->fittedTo(DestinationMediaSupport::of(['video'], 1));

        $this->assertSame('Hello', $fitted->body);
        $this->assertSame('https://example.com/post', $fitted->link);
        $this->assertSame(['text' => 'Hello'], $fitted->fields);
        $this->assertSame(['https://cdn.example.com/clip.mp4'], array_column($fitted->media, 'url'));
    }

    /**
     * @return list<array<string, string>>
     */
    private function media(): array
    {
        return [
            ['type' => 'image', 'url' => 'https://cdn.example.com/one.jpg'],
            ['type' => 'video', 'url' => 'https://cdn.example.com/clip.mp4'],
            ['type' => 'image', 'url' => 'https://cdn.example.com/two.jpg'],
            ['type' => 'image', 'url' => 'https://cdn.example.com/three.jpg'],
        ];
    }
}
