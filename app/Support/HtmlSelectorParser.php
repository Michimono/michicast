<?php

namespace App\Support;

use App\Exceptions\SourceFetchFailedException;
use DOMElement;

/**
 * Thin boundary for HTML CSS-selector extraction. Keeps the concrete parsing
 * library replaceable behind the web page source provider.
 */
interface HtmlSelectorParser
{
    /**
     * Load an HTML document for subsequent selector queries.
     *
     * @throws SourceFetchFailedException
     */
    public function load(string $html): void;

    /**
     * Elements matching a CSS selector. When $context is set, the selector is
     * resolved relative to that element.
     *
     * @return list<DOMElement>
     *
     * @throws SourceFetchFailedException
     */
    public function query(string $selector, ?DOMElement $context = null): array;

    /**
     * Visible text of an element with script and style nodes removed and markup stripped.
     */
    public function plainText(DOMElement $element): string;

    /**
     * Attribute value on an element, or null when missing or blank.
     */
    public function attribute(DOMElement $element, string $name): ?string;
}
