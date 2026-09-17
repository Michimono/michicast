<?php

namespace App\Support;

/**
 * Escapes and truncates a prepared message for Telegram's parse modes so the
 * Bot API accepts it and the 4096-character limit is never broken mid-token.
 */
class TelegramMessageFormatter
{
    public const MAX_LENGTH = 4096;

    public const PARSE_MODE_HTML = 'HTML';

    public const PARSE_MODE_MARKDOWN_V2 = 'MarkdownV2';

    /**
     * @return array{text: string, parse_mode: string}
     */
    public function format(PreparedMessage $message, string $parseMode): array
    {
        $parseMode = $this->normalizeParseMode($parseMode);
        $plain = $message->body;

        if (is_string($message->link) && trim($message->link) !== '') {
            $plain .= "\n\n".trim($message->link);
        }

        $escaped = match ($parseMode) {
            self::PARSE_MODE_MARKDOWN_V2 => $this->escapeMarkdownV2($plain),
            default => $this->escapeHtml($plain),
        };

        return [
            'text' => $this->truncate($escaped, $parseMode),
            'parse_mode' => $parseMode,
        ];
    }

    public function normalizeParseMode(string $parseMode): string
    {
        return match ($parseMode) {
            self::PARSE_MODE_MARKDOWN_V2, 'Markdown' => self::PARSE_MODE_MARKDOWN_V2,
            default => self::PARSE_MODE_HTML,
        };
    }

    public function escapeHtml(string $text): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $text,
        );
    }

    public function escapeMarkdownV2(string $text): string
    {
        return preg_replace(
            '/([_*\\[\\]()~`>#+\\-=|{}.!\\\\])/',
            '\\\\$1',
            $text,
        ) ?? $text;
    }

    public function truncate(string $text, string $parseMode = self::PARSE_MODE_HTML): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $truncated = mb_substr($text, 0, self::MAX_LENGTH - 1).'…';

        if ($parseMode === self::PARSE_MODE_HTML) {
            $truncated = (string) preg_replace('/&[^;]*$/', '', $truncated);
        }

        if ($parseMode === self::PARSE_MODE_MARKDOWN_V2 && str_ends_with($truncated, '\\')) {
            $truncated = mb_substr($truncated, 0, -1);
        }

        return $truncated;
    }
}
