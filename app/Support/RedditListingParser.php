<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Turns a subreddit's public Atom feed (https://www.reddit.com/r/{sub}/{sort}/.rss)
 * into normalized post entries.
 *
 * Each entry carries the post id (t3_…), title, author, permalink, publish time
 * and an HTML summary. The summary holds the self-post text and a "[link]"
 * anchor that points at the outbound URL for link, image and gallery posts.
 * Removed or deleted posts still appear in feeds; this parser skips them so
 * transforms and destinations only see posts with usable content.
 */
class RedditListingParser
{
    private const ATOM_NAMESPACE = 'http://www.w3.org/2005/Atom';

    private const MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss/';

    /**
     * @return list<array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     is_self: bool,
     *     linked_url: string|null,
     *     raw: array<string, mixed>
     * }>
     */
    public function parse(string $xml): array
    {
        $feed = $this->load($xml);

        if ($feed->getName() !== 'feed' || ! in_array(self::ATOM_NAMESPACE, $feed->getNamespaces(), true)) {
            throw new SourceFetchFailedException('The Reddit feed response had an unexpected shape.');
        }

        $entries = [];

        foreach ($feed->children(self::ATOM_NAMESPACE)->entry as $entry) {
            $normalized = $this->normalizeEntry($entry);

            if ($normalized !== null) {
                $entries[] = $normalized;
            }
        }

        return $entries;
    }

    private function load(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string(trim($xml), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException('The Reddit feed response could not be parsed.', previous: $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $feed instanceof SimpleXMLElement) {
            throw new SourceFetchFailedException('The Reddit feed response could not be parsed.');
        }

        return $feed;
    }

    /**
     * @return array{
     *     external_id: string,
     *     canonical_url: string,
     *     title: string,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     is_self: bool,
     *     linked_url: string|null,
     *     raw: array<string, mixed>
     * }|null
     */
    private function normalizeEntry(SimpleXMLElement $entry): ?array
    {
        $atom = $entry->children(self::ATOM_NAMESPACE);

        $externalId = $this->stringOrNull((string) $atom->id);

        if ($externalId === null) {
            return null;
        }

        // Feed ids are fullnames (t3_<id>); keep the bare id like the JSON listing did.
        if (str_starts_with($externalId, 't3_')) {
            $externalId = substr($externalId, 3);
        }

        $title = $this->stringOrNull((string) $atom->title) ?? '';
        $author = $this->author($atom);

        if ($this->isRemoved($title, $author)) {
            return null;
        }

        $permalink = $this->permalink($atom);
        $html = (string) $atom->content;
        $linked = $this->linkedUrl($html);
        $isSelf = $linked === null || ($permalink !== null && $this->sameUrl($linked, $permalink));
        $selfText = $this->selfText($html);

        if ($isSelf && ($selfText === '[removed]' || $selfText === '[deleted]')) {
            return null;
        }

        $canonicalUrl = $permalink ?? ($linked ?? 'https://www.reddit.com/comments/'.$externalId);
        $linkedUrl = $isSelf ? null : $linked;
        $published = $this->stringOrNull((string) $atom->published) ?? $this->stringOrNull((string) $atom->updated);

        return [
            'external_id' => $externalId,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            // Self posts expose the text body; link posts expose the outbound URL so
            // transforms have something sensible either way.
            'body' => $isSelf ? $selfText : $linkedUrl,
            'author' => $author,
            'published_at' => $this->parseDate($published),
            'is_self' => $isSelf,
            'linked_url' => $linkedUrl,
            'raw' => [
                'id' => $externalId,
                'title' => $title,
                'author' => $author,
                'permalink' => $permalink,
                'url' => $linked,
                'is_self' => $isSelf,
                'selftext' => $selfText,
                'thumbnail' => $this->thumbnail($entry),
                'published' => $published,
                'updated' => $this->stringOrNull((string) $atom->updated),
                'content_html' => $html,
            ],
        ];
    }

    private function author(SimpleXMLElement $atom): ?string
    {
        $name = $this->stringOrNull((string) $atom->author->name);

        if ($name === null) {
            return null;
        }

        return preg_replace('#^/?u/#', '', $name) ?? $name;
    }

    private function isRemoved(string $title, ?string $author): bool
    {
        return in_array($author, ['[deleted]', '[removed]'], true)
            || in_array($title, ['[deleted]', '[removed]'], true);
    }

    private function permalink(SimpleXMLElement $atom): ?string
    {
        foreach ($atom->link as $link) {
            // Elements read through children($namespace) look attributes up in that
            // namespace too, so read the plain attributes explicitly.
            $attributes = $link->attributes();
            $rel = (string) ($attributes['rel'] ?? '');

            if ($rel === '' || $rel === 'alternate') {
                return $this->stringOrNull((string) ($attributes['href'] ?? ''));
            }
        }

        return null;
    }

    /**
     * The summary ends with "[link]" (outbound URL, or the permalink for self
     * posts) and "[comments]" anchors.
     */
    private function linkedUrl(string $html): ?string
    {
        if (! preg_match('#<a\s+href="([^"]+)"\s*>\s*\[link\]\s*</a>#i', $html, $matches)) {
            return null;
        }

        return $this->stringOrNull(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function selfText(string $html): ?string
    {
        if (! preg_match('#<div class="md">(.*?)</div>\s*<!-- SC_ON -->#s', $html, $matches)) {
            return null;
        }

        $text = preg_replace(['#<br\s*/?>#i', '#</(p|li|h[1-6]|blockquote|pre|tr)>#i'], ["\n", "\n\n"], $matches[1]) ?? $matches[1];
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]*\n[ \t]*/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $this->stringOrNull($text);
    }

    private function thumbnail(SimpleXMLElement $entry): ?string
    {
        $thumbnail = $entry->children(self::MEDIA_NAMESPACE)->thumbnail;

        if ($thumbnail->count() === 0) {
            return null;
        }

        return $this->stringOrNull((string) $thumbnail->attributes()['url']);
    }

    private function sameUrl(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
