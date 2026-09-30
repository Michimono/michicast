<?php

namespace App\Support;

/**
 * Escapes and truncates a prepared message for Telegram's parse modes so the
 * Bot API accepts it and the 4096-character limit is never broken mid-token.
 *
 * MarkdownV2 preserves intentional formatting (*bold*, _italic_, ...) while
 * escaping reserved characters in plain text and inside entities. CommonMark
 * **bold** is normalized to Telegram's single-asterisk bold.
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
            self::PARSE_MODE_MARKDOWN_V2 => $this->prepareMarkdownV2($plain),
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

    /**
     * Escape every MarkdownV2 reserved character. Use for plain segments and
     * entity interiors — not for formatting delimiters themselves.
     */
    public function escapeMarkdownV2(string $text): string
    {
        return preg_replace(
            '/([_*\\[\\]()~`>#+\\-=|{}.!\\\\])/',
            '\\\\$1',
            $text,
        ) ?? $text;
    }

    /**
     * Ready a body for MarkdownV2: CommonMark **bold** -> *bold*, then escape
     * reserved chars while keeping intentional formatting entities intact.
     */
    public function prepareMarkdownV2(string $text): string
    {
        return $this->escapeMarkdownV2PreservingMarkup(
            $this->normalizeCommonMarkBold($text),
        );
    }

    /**
     * Convert unescaped **...** pairs to Telegram MarkdownV2 *...* bold.
     */
    public function normalizeCommonMarkBold(string $text): string
    {
        return preg_replace(
            '/(?<!\\\\)\*\*((?:\\\\.|[^*\\\\])+?)(?<!\\\\)\*\*/u',
            '*$1*',
            $text,
        ) ?? $text;
    }

    /**
     * Escape reserved characters outside and inside MarkdownV2 entities, but
     * leave entity delimiters alone so *bold* and friends still render.
     */
    public function escapeMarkdownV2PreservingMarkup(string $text): string
    {
        // Order matters: fenced/code/spoiler/underline before single markers.
        $pattern = '/```([\s\S]*?)```'
            .'|`([^`]+)`'
            .'|\|\|(.+?)\|\|'
            .'|__([^_]+?)__'
            .'|\*((?:\\\\.|[^*\\\\])+)\*'
            .'|_((?:\\\\.|[^_\\\\])+)_'
            .'|~((?:\\\\.|[^~\\\\])+?)~'
            .'|\[([^\]]+)\]\(([^)]+)\)'
            .'/u';

        if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $this->escapeMarkdownV2($text);
        }

        $result = '';
        $offset = 0;

        foreach ($matches as $match) {
            $full = $match[0][0];
            $start = $match[0][1];

            if ($start > $offset) {
                $result .= $this->escapeMarkdownV2(substr($text, $offset, $start - $offset));
            }

            $result .= $this->rebuildMarkdownV2Entity($match);
            $offset = $start + strlen($full);
        }

        if ($offset < strlen($text)) {
            $result .= $this->escapeMarkdownV2(substr($text, $offset));
        }

        return $result;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $match
     */
    private function rebuildMarkdownV2Entity(array $match): string
    {
        if (($match[1][1] ?? -1) >= 0) {
            return '```'.$this->escapeMarkdownV2($match[1][0]).'```';
        }

        if (($match[2][1] ?? -1) >= 0) {
            return '`'.$this->escapeMarkdownV2($match[2][0]).'`';
        }

        if (($match[3][1] ?? -1) >= 0) {
            return '||'.$this->escapeMarkdownV2($match[3][0]).'||';
        }

        if (($match[4][1] ?? -1) >= 0) {
            return '__'.$this->escapeMarkdownV2($match[4][0]).'__';
        }

        if (($match[5][1] ?? -1) >= 0) {
            return '*'.$this->escapeMarkdownV2($match[5][0]).'*';
        }

        if (($match[6][1] ?? -1) >= 0) {
            return '_'.$this->escapeMarkdownV2($match[6][0]).'_';
        }

        if (($match[7][1] ?? -1) >= 0) {
            return '~'.$this->escapeMarkdownV2($match[7][0]).'~';
        }

        if (($match[8][1] ?? -1) >= 0) {
            $label = $this->escapeMarkdownV2($match[8][0]);
            $url = $this->escapeMarkdownV2LinkUrl($match[9][0] ?? '');

            return '['.$label.']('.$url.')';
        }

        return $this->escapeMarkdownV2($match[0][0]);
    }

    /**
     * Link URLs in MarkdownV2 only need ) and \ escaped inside the (...).
     */
    private function escapeMarkdownV2LinkUrl(string $url): string
    {
        return str_replace(
            ['\\', ')'],
            ['\\\\', '\\)'],
            $url,
        );
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