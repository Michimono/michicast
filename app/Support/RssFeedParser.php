<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * Turns an RSS or Atom document into a list of entry arrays. Kept behind the
 * RSS provider so the parsing approach stays replaceable.
 */
class RssFeedParser
{
    private const MEDIA_NAMESPACE = 'http://search.yahoo.com/mrss';

    /**
     * @return list<array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     media: list<array<string, mixed>>,
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
     *     media: list<array<string, mixed>>,
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
                media: $this->media($item, ['description', 'encoded'], $this->nullable($link)),
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
     *     media: list<array<string, mixed>>,
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
                media: $this->media($entry, ['content', 'summary'], $this->nullable($link)),
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
     * @param  list<array<string, mixed>>  $media
     * @param  array<string, mixed>  $raw
     * @return array{
     *     external_id: string|null,
     *     canonical_url: string|null,
     *     title: string|null,
     *     body: string|null,
     *     author: string|null,
     *     published_at: CarbonImmutable|null,
     *     media: list<array<string, mixed>>,
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
        array $media,
        array $raw,
    ): array {
        return [
            'external_id' => $externalId,
            'canonical_url' => $canonicalUrl,
            'title' => $title,
            'body' => $body,
            'author' => $author,
            'published_at' => $publishedAt,
            'media' => $media,
            'raw' => $raw,
        ];
    }

    /**
     * The entry's pictures and videos, in feed order: what the feed declares
     * (enclosures, Media RSS), its thumbnail when it declares no picture, and
     * the images in the entry's own HTML when it declares nothing at all.
     *
     * @param  list<string>  $bodyElements  Local names of the elements that hold the entry's HTML.
     * @return list<array<string, mixed>>
     */
    private function media(DOMElement $entry, array $bodyElements, ?string $baseUrl): array
    {
        $declared = [];
        $thumbnails = [];
        $this->collectDeclaredMedia($entry, $declared, $thumbnails);

        $media = MediaList::normalize($declared);

        if (! MediaList::hasImage($media)) {
            $media = MediaList::normalize([...$media, ...$thumbnails]);
        }

        if ($media !== []) {
            return $media;
        }

        $images = [];

        foreach ($entry->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower((string) $child->localName), $bodyElements, true)) {
                array_push($images, ...$this->htmlImages($child, $baseUrl));
            }
        }

        return MediaList::normalize($images);
    }

    /**
     * @param  list<array<string, mixed>>  $declared
     * @param  list<array<string, mixed>>  $thumbnails
     */
    private function collectDeclaredMedia(DOMElement $parent, array &$declared, array &$thumbnails): void
    {
        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower((string) $child->localName);
            $isMediaRss = str_starts_with((string) $child->namespaceURI, self::MEDIA_NAMESPACE);

            if ($isMediaRss && $name === 'group') {
                $this->collectDeclaredMedia($child, $declared, $thumbnails);

                continue;
            }

            if ($isMediaRss && $name === 'content') {
                $medium = strtolower($child->getAttribute('medium'));

                if ($medium === '' || $medium === MediaList::TYPE_IMAGE || $medium === MediaList::TYPE_VIDEO) {
                    $declared[] = [
                        'type' => $medium,
                        'url' => $child->getAttribute('url'),
                        'mime_type' => $child->getAttribute('type'),
                        'width' => $child->getAttribute('width'),
                        'height' => $child->getAttribute('height'),
                    ];
                }

                // A video's poster sits inside its media:content.
                $this->collectDeclaredMedia($child, $declared, $thumbnails);

                continue;
            }

            if ($isMediaRss && $name === 'thumbnail') {
                $thumbnails[] = [
                    'type' => MediaList::TYPE_IMAGE,
                    'url' => $child->getAttribute('url'),
                    'width' => $child->getAttribute('width'),
                    'height' => $child->getAttribute('height'),
                ];

                continue;
            }

            if ($name === 'enclosure') {
                $declared[] = [
                    'url' => $child->getAttribute('url'),
                    'mime_type' => $child->getAttribute('type'),
                ];

                continue;
            }

            if ($name === 'link' && strtolower($child->getAttribute('rel')) === 'enclosure') {
                $declared[] = [
                    'url' => $child->getAttribute('href'),
                    'mime_type' => $child->getAttribute('type'),
                ];
            }
        }
    }

    /**
     * Images inside an entry's HTML, whether the feed carries it as escaped
     * text or as inline XHTML. Tracking pixels and inline data are skipped.
     *
     * @return list<array<string, mixed>>
     */
    private function htmlImages(DOMElement $body, ?string $baseUrl): array
    {
        $nodes = iterator_to_array($body->getElementsByTagNameNS('*', 'img'), false);
        $html = $body->textContent;

        if (stripos($html, '<img') !== false) {
            $previous = libxml_use_internal_errors(true);

            try {
                $fragment = new DOMDocument;

                if ($fragment->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                    array_push($nodes, ...iterator_to_array($fragment->getElementsByTagName('img'), false));
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        $images = [];

        foreach ($nodes as $node) {
            if ($this->isTrackingPixel($node)) {
                continue;
            }

            $source = trim($node->getAttribute('src'));

            if ($source === '' || str_starts_with(strtolower($source), 'data:')) {
                $source = trim($node->getAttribute('data-src'));
            }

            $url = $this->absoluteUrl($source, $baseUrl);

            if ($url === null) {
                continue;
            }

            $images[] = [
                'type' => MediaList::TYPE_IMAGE,
                'url' => $url,
                'alt' => $node->getAttribute('alt'),
                'width' => $node->getAttribute('width'),
                'height' => $node->getAttribute('height'),
            ];
        }

        return $images;
    }

    private function isTrackingPixel(DOMElement $image): bool
    {
        foreach (['width', 'height'] as $attribute) {
            $value = trim($image->getAttribute($attribute));

            if (is_numeric($value) && (int) $value <= 1) {
                return true;
            }
        }

        return false;
    }

    private function absoluteUrl(string $url, ?string $baseUrl): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        if ($baseUrl === null || preg_match('#^https?://#i', $baseUrl) !== 1) {
            return null;
        }

        try {
            return (string) UriResolver::resolve(new Uri($baseUrl), new Uri($url));
        } catch (Throwable) {
            return null;
        }
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
