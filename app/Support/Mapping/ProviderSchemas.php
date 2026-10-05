<?php

namespace App\Support\Mapping;

use App\Support\Mapping\Schemas\RecordingDestinationSchema;
use App\Support\Mapping\Schemas\RssSourceSchema;
use App\Support\Mapping\Schemas\TelegramDestinationSchema;
use App\Support\Mapping\Schemas\TwitterSourceSchema;
use App\Support\Mapping\Schemas\WebPageSourceSchema;

/**
 * Resolves content schemas and sample data for registered providers.
 */
final class ProviderSchemas
{
    public function sourceSchema(string $type): ?ContentSchema
    {
        return match ($type) {
            'rss' => RssSourceSchema::make(),
            'twitter' => TwitterSourceSchema::make(),
            'web_page' => WebPageSourceSchema::make(),
            'fake' => new ContentSchema(
                version: RssSourceSchema::make()->version,
                fields: RssSourceSchema::make()->fields,
                providerType: 'fake',
                label: 'Fake source',
            ),
            default => null,
        };
    }

    public function destinationSchema(string $type): ?ContentSchema
    {
        return match ($type) {
            'telegram' => TelegramDestinationSchema::make(),
            'recording' => RecordingDestinationSchema::make(),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sourceSample(string $type): ?array
    {
        return match ($type) {
            'rss', 'fake' => RssSourceSchema::sample(),
            'twitter' => TwitterSourceSchema::sample(),
            'web_page' => WebPageSourceSchema::sample(),
            default => null,
        };
    }
}
