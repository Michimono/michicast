<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use App\Models\ContentItem;

/**
 * A source that returns the items written into its own configuration. It is
 * registered in tests so suites can steer fetch and connection behaviour
 * without hitting the network, and is hidden from source forms.
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

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'feed_url',
                label: 'Feed URL',
                type: ConfigurationFieldType::Url,
                hint: 'The address this fake source pretends to read.',
            ),
        ];
    }

    public function capabilities(): SourceProviderCapabilities
    {
        return new SourceProviderCapabilities(
            supportsConnectionTest: true,
            offeredInForms: false,
        );
    }

    public function testConnection(SourceDefinition $source): ConnectionTestResult
    {
        $feedUrl = $source->config('feed_url');

        if (! is_string($feedUrl) || trim($feedUrl) === '') {
            return ConnectionTestResult::failure('The fake source has no feed URL to reach.');
        }

        if (str_contains($feedUrl, '/broken')) {
            return ConnectionTestResult::failure('The fake source could not reach that feed URL.');
        }

        $items = $this->configuredItems($source);
        $count = count($items);

        if ($count === 0) {
            return ConnectionTestResult::success("Reached {$feedUrl}. No sample items are configured.");
        }

        $titles = [];

        foreach (array_slice($items, 0, 3) as $item) {
            $title = $item['title'] ?? $item['canonical_url'] ?? $item['external_id'] ?? null;

            if (is_string($title) && $title !== '') {
                $titles[] = $title;
            }
        }

        $sample = $titles === []
            ? "Reached {$feedUrl} and found {$count} configured items."
            : "Reached {$feedUrl} and found {$count} configured items. Sample: ".implode(', ', $titles).'.';

        return ConnectionTestResult::success($sample);
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
