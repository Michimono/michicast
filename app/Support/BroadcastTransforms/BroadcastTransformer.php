<?php

namespace App\Support\BroadcastTransforms;

use App\Models\ContentItem;
use App\Support\PreparedMessage;
use Illuminate\Support\Str;

/**
 * Turns a content item into a PreparedMessage using a broadcast's template and
 * formatting rules. Missing config uses the default template; destinations own escaping.
 */
class BroadcastTransformer
{
    private const SUMMARY_WORDS = 40;

    /**
     * @param  array<string, mixed>|null  $transform
     */
    public function transform(ContentItem $item, ?array $transform, ?string $sourceName = null): PreparedMessage
    {
        $template = $this->template($transform);
        $values = $this->placeholderValues($item, $sourceName);
        $text = $this->substitute($template, $values);

        foreach ($this->rules($transform) as $rule) {
            $text = $rule->apply($text);
        }

        $media = is_array($item->media) ? $item->media : [];

        return new PreparedMessage(
            body: $text,
            link: is_string($item->canonical_url) && $item->canonical_url !== ''
                ? $item->canonical_url
                : null,
            media: $media,
        );
    }

    /**
     * Render with sample values for the transform form preview.
     *
     * @param  array<string, mixed>|null  $transform
     */
    public function preview(?array $transform): PreparedMessage
    {
        return $this->transform($this->sampleItem(), $transform, 'Pocket news feed');
    }

    /**
     * @param  array<string, mixed>|null  $transform
     */
    public function template(?array $transform): string
    {
        $template = $transform['template'] ?? null;

        if (! is_string($template) || trim($template) === '') {
            return TemplatePlaceholders::DEFAULT_TEMPLATE;
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>|null  $transform
     * @return list<BroadcastTransformRule>
     */
    public function rules(?array $transform): array
    {
        $raw = $transform['rules'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $rules = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rule = BroadcastTransformRule::fromArray($entry);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function substitute(string $template, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/u',
            function (array $matches) use ($values): string {
                return $values[$matches[1]] ?? '';
            },
            $template,
        );
    }

    /**
     * @return array<string, string>
     */
    private function placeholderValues(ContentItem $item, ?string $sourceName): array
    {
        $body = (string) ($item->body ?? '');

        return [
            'title' => (string) ($item->title ?? ''),
            'body' => $body,
            'summary' => $this->summary($body),
            'url' => (string) ($item->canonical_url ?? ''),
            'author' => (string) ($item->author ?? ''),
            'published' => $item->published_at?->timezone('UTC')->format('j M Y') ?? '',
            'source' => (string) ($sourceName ?? ''),
        ];
    }

    private function summary(string $body): string
    {
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($plain === '') {
            return '';
        }

        return (string) Str::of($plain)->words(self::SUMMARY_WORDS, '…');
    }

    private function sampleItem(): ContentItem
    {
        $item = new ContentItem;
        $item->forceFill([
            'title' => 'Sample Pocket news',
            'body' => '<p>A short body with <b>markup</b> for previewing transforms.</p>',
            'author' => 'Official Account',
            'canonical_url' => 'https://example.com/posts/sample',
            'published_at' => now('UTC'),
            'media' => [],
        ]);

        return $item;
    }
}
