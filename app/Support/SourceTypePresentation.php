<?php

namespace App\Support;

/**
 * How a source type looks on the source forms: a short tile name, a one-line
 * descriptor and a text glyph. Unknown types fall back to the provider label,
 * so a new provider still renders without touching this map.
 */
class SourceTypePresentation
{
    private const KNOWN = [
        'rss' => ['name' => 'RSS / Atom', 'descriptor' => 'Feed URL', 'icon' => 'RSS'],
        'reddit' => ['name' => 'Reddit', 'descriptor' => 'Public JSON', 'icon' => 'r/'],
        'twitter' => ['name' => 'Twitter / X', 'descriptor' => 'Public profile', 'icon' => '@'],
        'web_page' => ['name' => 'Web page', 'descriptor' => 'CSS selectors', 'icon' => '</>'],
    ];

    /**
     * @return array{name: string, descriptor: string, icon: string}
     */
    public static function for(string $type, string $label): array
    {
        return self::KNOWN[$type] ?? [
            'name' => $label,
            'descriptor' => '',
            'icon' => mb_strtoupper(mb_substr($label, 0, 1)),
        ];
    }
}
