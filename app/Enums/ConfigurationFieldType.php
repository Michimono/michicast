<?php

namespace App\Enums;

/**
 * The field types a provider may ask a configuration form to render. The set
 * is deliberately small: this is a schema-driven form, not a form builder.
 */
enum ConfigurationFieldType: string
{
    case Text = 'text';
    case Url = 'url';
    case Number = 'number';
    case Textarea = 'textarea';
    case Select = 'select';
}
