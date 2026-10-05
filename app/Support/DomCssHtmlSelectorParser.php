<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\ExpressionErrorException;
use Throwable;

/**
 * DOMDocument + Symfony CssSelector implementation of HtmlSelectorParser.
 */
class DomCssHtmlSelectorParser implements HtmlSelectorParser
{
    private ?DOMDocument $document = null;

    private ?DOMXPath $xpath = null;

    private CssSelectorConverter $converter;

    public function __construct(?CssSelectorConverter $converter = null)
    {
        $this->converter = $converter ?? new CssSelectorConverter(true);
    }

    public function load(string $html): void
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT,
            );
        } catch (Throwable $exception) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            throw new SourceFetchFailedException('The page could not be parsed as HTML.', previous: $exception);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true || ! $document->documentElement instanceof DOMElement) {
            throw new SourceFetchFailedException('The page could not be parsed as HTML.');
        }

        $this->document = $document;
        $this->xpath = new DOMXPath($document);
    }

    public function query(string $selector, ?DOMElement $context = null): array
    {
        $this->ensureLoaded();

        $selector = trim($selector);

        if ($selector === '') {
            throw new SourceFetchFailedException('A CSS selector is required.');
        }

        try {
            $expression = $this->converter->toXPath($selector);
        } catch (ExpressionErrorException $exception) {
            throw new SourceFetchFailedException(
                'The CSS selector ['.$selector.'] is not valid.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new SourceFetchFailedException(
                'The CSS selector ['.$selector.'] is not valid.',
                previous: $exception,
            );
        }

        $nodes = $this->xpath->query($expression, $context);

        if ($nodes === false) {
            throw new SourceFetchFailedException('The CSS selector ['.$selector.'] could not be evaluated.');
        }

        $elements = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function plainText(DOMElement $element): string
    {
        $clone = $element->cloneNode(true);

        if (! $clone instanceof DOMElement) {
            return '';
        }

        $this->removeNodesByTagName($clone, 'script');
        $this->removeNodesByTagName($clone, 'style');
        $this->removeNodesByTagName($clone, 'noscript');

        $text = html_entity_decode($clone->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    public function attribute(DOMElement $element, string $name): ?string
    {
        if (! $element->hasAttribute($name)) {
            return null;
        }

        $value = trim(html_entity_decode($element->getAttribute($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    private function removeNodesByTagName(DOMElement $root, string $tagName): void
    {
        $nodes = [];

        foreach ($root->getElementsByTagName($tagName) as $node) {
            $nodes[] = $node;
        }

        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function ensureLoaded(): void
    {
        if ($this->document === null || $this->xpath === null) {
            throw new SourceFetchFailedException('The page could not be parsed as HTML.');
        }
    }
}
