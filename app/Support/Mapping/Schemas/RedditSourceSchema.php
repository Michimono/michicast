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
                new SchemaField('title', 'Title', FieldType::Text, 'Post title as shown on Reddit', false),
                new SchemaField('text', 'Text', FieldType::Text, 'What the poster wrote, as plain text. Empty for image, gallery or link posts without a caption', false),
                new SchemaField('linked_url', 'Linked URL', FieldType::Url, 'Where the post points: the full-size image, the gallery page or the article. Empty for text posts', false),
                new SchemaField(
                    name: 'preview_image',
                    label: 'Preview image',
                    type: FieldType::Array,
                    description: 'Picture to attach: the full-size image for image posts, otherwise Reddit\'s preview thumbnail. Empty when Reddit has no picture',
                    itemSchema: new SchemaField('item', 'Image', FieldType::Media),
                ),
                new SchemaField('link', 'Link', FieldType::Url, 'Link to the post and its comments on Reddit', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'When the post was submitted', false),
                new SchemaField('author', 'Author', FieldType::Text, 'Username of the poster, without u/', false),
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
