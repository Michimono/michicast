<?php

namespace App\Support\Mapping\Schemas;

use App\Support\Mapping\ContentSchema;
use App\Support\Mapping\FieldType;
use App\Support\Mapping\SchemaField;

final class TwitterSourceSchema
{
    public static function make(): ContentSchema
    {
        return new ContentSchema(
            version: 1,
            providerType: 'twitter',
            label: 'Twitter / X (public profile)',
            fields: [
                new SchemaField('body', 'Body', FieldType::Text, 'Full post text', false),
                new SchemaField('link', 'Link', FieldType::Url, 'Canonical post URL', false),
                new SchemaField('published_at', 'Published', FieldType::Datetime, 'Publication time', false),
                new SchemaField('author', 'Author', FieldType::Text, 'Display name of the account', false),
                new SchemaField(
                    name: 'media',
                    label: 'Images',
                    type: FieldType::Array,
                    description: 'Images or videos attached to the post',
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
            'body' => 'We will be at the International Astronautical Congress Oct. 5-9: https://go.nasa.gov/iac',
            'link' => 'https://x.com/NASA/status/2105369076662960310',
            'published_at' => '2026-09-30T18:47:58+00:00',
            'author' => 'NASA',
            'media' => [
                [
                    'type' => 'image',
                    'url' => 'https://pbs.twimg.com/media/sample.jpg',
                    'alt' => 'Congress hall',
                ],
            ],
        ];
    }
}
