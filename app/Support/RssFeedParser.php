<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

/**
 * Turns an RSS or Atom document into a list of entry arrays. Kept behind the
 * RSS provider so the parsing approach stays replaceable.
 */
class RssFeedParser
{
    /**
     * @return list<array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }>
     */
    public function parse(string $xml): array
    {
        $document = $this->document($xml);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            throw new SourceFetchFailedException('The feed could not be parsed.');
        }

        $localName = strtolower($root->localName ?? $root->nodeName);

        return match ($localName) {
            'rss', 'rdf:rdf', 'rdf' => $this->parseRss($document),
            'feed' => $this->parseAtom($document),
            default => throw new SourceFetchFailedException('The feed is not a recognised RSS or Atom document.'),
        };
    }

    /**
     * @return list<array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }>
     */
    private function parseRss(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $items = [];

        foreach ($xpath->query('//item') ?: [] as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $title = $this->childText($item, 'title');
            $link = $this->childText($item, 'link');
            $guid = $this->childText($item, 'guid');
            $body = $this->childText($item, 'description')
                ?? $this->childText($item, 'content:encoded')
                ?? $this->namespacedChildText($item, 'http://purl.org/rss/1.0/modules/content/', 'encoded');
            $author = $this->childText($item, 'author')
                ?? $this->namespacedChildText($item, 'http://purl.org/dc/elements/1.1/', 'creator')
                ?? $this->childText($item, 'dc:creator');
            $published = $this->parseDate(
                $this->childText($item, 'pubDate')
                    ?? $this->namespacedChildText($item, 'http://purl.org/dc/elements/1.1/', 'date')
                    ?? $this->childText($item, 'dc:date'),
            );

            $items[] = $this->entry(
                externalId: $this->nullable($guid) ?? $this->nullable($link),
                canonicalUrl: $this->nullable($link),
                title: $this->nullable($title),
                body: $this->nullable($body),
                author: $this->nullable($author),
                publishedAt: $published,
                raw: [
                    'title' => $title,
                    'link' => $link,
                    'guid' => $guid,
                    'description' => $body,
                    'author' => $author,
                    'pubDate' => $this->childText($item, 'pubDate'),
                ],
            );
        }

        return $items;
    }

    /**
     * @return list<array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }>
     */
    private function parseAtom(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        $entries = $xpath->query('//atom:entry') ?: $xpath->query('//entry');
        $items = [];

        foreach ($entries ?: [] as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }

            $title = $this->atomText($entry, 'title');
            $id = $this->atomText($entry, 'id');
            $link = $this->atomLink($entry);
            $body = $this->atomText($entry, 'content') ?? $this->atomText($entry, 'summary');
            $author = $this->atomAuthor($entry);
            $published = $this->parseDate(
                $this->atomText($entry, 'published') ?? $this->atomText($entry, 'updated'),
            );

            $items[] = $this->entry(
                externalId: $this->nullable($id) ?? $this->nullable($link),
                canonicalUrl: $this->nullable($link),
                title: $this->nullable($title),
                body: $this->nullable($body),
                author: $this->nullable($author),
                publishedAt: $published,
                raw: [
                    'id' => $id,
                    'title' => $title,
                    'link' => $link,
                    'summary' => $body,
                    'author' => $author,
                    'published' => $this->atomText($entry, 'published'),
                    'updated' => $this->atomText($entry, 'updated'),
                ],
            );
        }

        return $items;
    }

    private function document(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw new SourceFetchFailedException('The feed was empty.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

            if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
                throw new SourceFetchFailedException('The feed could not be parsed.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     raw: array<string, mixed>
     * }
     */
    private function entry(
        ?string $externalId,
        ?string $canonicalUrl,
        ?string $title,
        ?string $body,
        ?string $author,
        ?CarbonImmutable $publishedAt,
        array $raw,
    ): array {
        return [
            'external_id' => $externalId,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'body' => $body,
            'author' => $author,
            'published_at' => $publishedAt,
            'raw' => $raw,
        ];
    }

    private function childText(DOMElement $parent, string $name): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $local = strtolower($child->localName ?? $child->nodeName);

            if ($local === strtolower($name) || strtolower($child->nodeName) === strtolower($name)) {
                return $this->textOf($child);
            }
        }

        return null;
    }

    private function namespacedChildText(DOMElement $parent, string $namespace, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->namespaceURI === $namespace && strtolower((string) $child->localName) === strtolower($localName)) {
                return $this->textOf($child);
            }
        }

        return null;
    }

    private function atomText(DOMElement $entry, string $localName): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (strtolower((string) $child->localName) !== strtolower($localName)) {
                continue;
            }

            return $this->textOf($child);
        }

        return null;
    }

    private function atomLink(DOMElement $entry): ?string
    {
        $fallback = null;

        foreach ($entry->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower((string) $child->localName) !== 'link') {
                continue;
            }

            $href = $child->getAttribute('href');

            if ($href === '') {
                continue;
            }

            $rel = $child->getAttribute('rel');

            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    private function atomAuthor(DOMElement $entry): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower((string) $child->localName) !== 'author') {
                continue;
            }

            foreach ($child->childNodes as $nameNode) {
                if ($nameNode instanceof DOMElement && strtolower((string) $nameNode->localName) === 'name') {
                    return $this->textOf($nameNode);
                }
            }
        }

        return null;
    }

    private function textOf(DOMNode $node): ?string
    {
        $text = trim($node->textContent ?? '');

        return $text === '' ? null : $text;
    }

    private function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function parseDate(?string $value): ?CarbonImmutable
    {
        $value = $this->nullable($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
