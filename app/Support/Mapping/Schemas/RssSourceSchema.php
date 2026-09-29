<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class RssSourceSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'rss',
            label: 'RSS / Atom feed',
            fields: [
                new SchemaField('title', 'Title', FieldType::Text, 'Entry title', false),
                new SchemaField('body', 'Body', FieldType::Text, 'Description or content', false),
                new SchemaField('link', 'Link', FieldType::Url, 'Canonical entry URL', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'Publication time', false),
                new SchemaField('author', 'Author', FieldType::Text, 'Author name', false),
                new SchemaField(
                    name: 'categories',
                    label: 'Categories',
                    type: FieldType::Array,
                    description: 'Tags or categories from the feed',
                    itemSchema: new SchemaField('item', 'Category', FieldType::Text),
                ),
                new SchemaField(
                    name: 'media',
                    label: 'Images',
                    type: FieldType::Array,
                    description: 'Images attached to the entry',
                    itemSchema: new SchemaField('item', 'Image', FieldType::Media),
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'title' => 'New Pokémon expansion announced',
            'body' => 'Pokémon has announced a new expansion with fresh cards and adventures.',
            'link' => 'https://example.com/posts/sample',
            'published_at' => '2026-09-24T12:00:00+00:00',
            'author' => 'Official Account',
            'categories' => ['news', 'pocket'],
            'media' => [
                [
                    'type' => 'image',
                    'url' => 'https://example.com/images/sample.jpg',
                    'alt' => 'Sample',
                ],
            ],
        ];
    }
}
