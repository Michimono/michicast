<?php

namespace Tests\Unit\Support;

use App\Support\PreparedMessage;
use App\Support\TelegramMessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramMessageFormatterTest extends TestCase
{
    #[Test]
    #[DataProvider('markdownV2Cases')]
    public function it_prepares_markdown_v2_for_safe_bold(string $input, string $expected): void
    {
        $formatter = new TelegramMessageFormatter;

        $this->assertSame($expected, $formatter->prepareMarkdownV2($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markdownV2Cases(): array
    {
        return [
            'commonmark double asterisk bold' => ['**Hello**', '*Hello*'],
            'native single asterisk bold' => ['*Hello*', '*Hello*'],
            'bold with reserved interior' => ['**Hello_world!**', '*Hello\\_world\\!*'],
            'plain reserved chars' => ['a_b*c', 'a\\_b\\*c'],
            'mixed markup and plain' => ['**Title** and plain_text', '*Title* and plain\\_text'],
            'italic preserved' => ['_Hello world_', '_Hello world_'],
        ];
    }

    #[Test]
    public function format_uses_prepare_for_markdown_v2(): void
    {
        $formatted = (new TelegramMessageFormatter)->format(
            new PreparedMessage('**{{not a template}}**'),
            TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
        );

        $this->assertSame('*\\{\\{not a template\\}\\}*', $formatted['text']);
        $this->assertSame('MarkdownV2', $formatted['parse_mode']);
    }
}