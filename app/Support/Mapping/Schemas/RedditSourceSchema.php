<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class RedditSourceSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'reddit',
            label: 'Reddit (public feed)',
            fields: [
                new SchemaField('title', 'Title', FieldType::Text, 'Post title', false),
                new SchemaField('body', 'Body', FieldType::Text, 'Post text; link and image posts add their linked URL', false),
                new SchemaField('text', 'Text', FieldType::Text, 'Post text only; empty for bare image and link posts', false),
                new SchemaField('linked_url', 'Linked URL', FieldType::Url, 'Where the post points: image, gallery or article; empty for text posts', false),
                new SchemaField(
                    name: 'preview_image',
                    label: 'Preview image',
                    type: FieldType::Array,
                    description: 'The full-size image for image posts, otherwise Reddit\'s preview thumbnail',
                    itemSchema: new SchemaField('item', 'Image', FieldType::Media),
                ),
                new SchemaField('link', 'Link', FieldType::Url, 'Canonical Reddit post URL', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'Creation time', false),
                new SchemaField('author', 'Author', FieldType::Text, 'Reddit username', false),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'title' => 'I got this card',
            'body' => "I still do not know how to play\n\nhttps://i.redd.it/sample.jpeg",
            'text' => 'I still do not know how to play',
            'linked_url' => 'https://i.redd.it/sample.jpeg',
            'preview_image' => [
                [
                    'type' => 'image',
                    'url' => 'https://i.redd.it/sample.jpeg',
                ],
            ],
            'link' => 'https://www.reddit.com/r/PTCGP/comments/abc123/i_got_this_card/',
            'published_at' => '2026-10-05T12:00:00+00:00',
            'author' => 'card_collector',
        ];
    }
}
