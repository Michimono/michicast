<?php

namespace App\Support;

use App\Models\ContentItem;

/**
 * A source that returns the items written into its own configuration. It keeps
 * the registry and the source screens buildable until the first real provider
 * lands, and gives tests a provider they can steer.
 */
class FakeSourceProvider implements SourceProvider
{
    public function type(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake source';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'feed_url' => ['required', 'url'],
            'items' => ['sometimes', 'array'],
        ];
    }

    public function capabilities(): SourceProviderCapabilities
    {
        return new SourceProviderCapabilities(supportsConnectionTest: true);
    }

    public function testConnection(SourceDefinition $source): ConnectionTestResult
    {
        $feedUrl = $source->config('feed_url');

        if (! is_string($feedUrl) || trim($feedUrl) === '') {
            return ConnectionTestResult::failure('The fake source has no feed URL to reach.');
        }

        return ConnectionTestResult::success("Reached {$feedUrl}.");
    }

    /**
     * @return list<ContentItem>
     */
    public function fetch(SourceDefinition $source): array
    {
        $items = [];

        foreach ($this->configuredItems($source) as $item) {
            $items[] = ContentItem::normalize([
                'project_id' => $source->projectId,
                'source_id' => $source->sourceId,
                'external_id' => $item['external_id'] ?? null,
                'canonical_url' => $item['canonical_url'] ?? null,
                'title' => $item['title'] ?? null,
                'body' => $item['body'] ?? null,
                'author' => $item['author'] ?? null,
                'published_at' => $item['published_at'] ?? null,
                'media' => $item['media'] ?? null,
                'metadata' => ['provider' => $this->type()],
                'raw_payload' => $item,
            ]);
        }

        return $items;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function configuredItems(SourceDefinition $source): array
    {
        $configured = $source->config('items', []);

        if (! is_array($configured)) {
            return [];
        }

        $items = [];

        foreach ($configured as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
