<?php

namespace App\Support;

/**
 * How a destination type looks on the destination forms: a short tile name, a
 * one-line descriptor and a text glyph. Unknown types fall back to the provider
 * label, so a new provider still renders without touching this map.
 */
class DestinationTypePresentation
{
    private const KNOWN = [
        'telegram' => ['name' => 'Telegram', 'descriptor' => 'Bot API', 'icon' => 'TG'],
        'recording' => ['name' => 'Activity log', 'descriptor' => 'Testing', 'icon' => 'LOG'],
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
