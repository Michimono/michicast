<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class WebPageSourceSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'web_page',
            label: 'Web page (CSS selectors)',
            fields: [
                new SchemaField('title', 'Title', FieldType::Text, 'Article title', false),
                new SchemaField('body', 'Body', FieldType::Text, 'Summary or body text', false),
                new SchemaField('link', 'Link', FieldType::Url, 'Canonical article URL', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'Publication time (fetch time when the page has no date)', false),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'title' => 'City opens the riverside park',
            'body' => 'The riverside park opened this morning after two years of work.',
            'link' => 'https://example.com/news/riverside-park',
            'published_at' => '2026-10-05T10:00:00+00:00',
        ];
    }
}
