<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The plain-text part of an email made from its HTML, for an email given
 * without one. Links keep their address after their label, so a link to
 * {{unsubscribe_url}} still gives the text its unsubscribe address, and
 * placeholders pass through untouched.
 */
class PlainText
{
    /**
     * Elements that stand apart from what follows them by a blank line.
     */
    protected const PARAGRAPHS = ['blockquote', 'dl', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ol', 'p', 'pre', 'table', 'ul'];

    /**
     * Elements that start on a line of their own.
     */
    protected const BLOCKS = [
        'address', 'article', 'aside', 'center', 'div', 'dt', 'dd', 'figure', 'footer', 'form',
        'header', 'main', 'nav', 'section', 'tbody', 'thead', 'tfoot', 'tr',
    ];

    protected const SKIPPED = ['head', 'script', 'style', 'title', 'template', 'noscript'];

    public static function fromHtml(string $html): string
    {
        $document = Html::parse($html);

        $text = static::walk($document->documentElement ?? $document);

        // Whitespace inside a line collapses as a browser would; line breaks
        // stay, at most one blank line in a row.
        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            explode("\n", $text),
        );

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    protected static function walk(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->data);
        }

        if (! $node instanceof DOMElement && ! $node instanceof DOMDocument) {
            return '';
        }

        $name = $node instanceof DOMElement ? strtolower($node->tagName) : '';

        if (in_array($name, static::SKIPPED, true) || ($node instanceof DOMElement && static::isHidden($node))) {
            return '';
        }

        if ($name === 'br') {
            return "\n";
        }

        if ($name === 'hr') {
            return "\n----\n";
        }

        if ($name === 'img') {
            $alt = trim($node->getAttribute('alt'));

            return $alt === '' ? '' : " {$alt} ";
        }

        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= static::walk($child);
        }

        if ($name === 'a') {
            return static::link($node, $inner);
        }

        if ($name === 'li') {
            return "\n- ".trim($inner);
        }

        if (in_array($name, ['td', 'th'], true)) {
            return ' '.$inner.' ';
        }

        if (in_array($name, static::PARAGRAPHS, true)) {
            return "\n\n".$inner."\n\n";
        }

        if (in_array($name, static::BLOCKS, true)) {
            return "\n".$inner."\n";
        }

        return $inner;
    }

    protected static function link(DOMElement $link, string $label): string
    {
        $href = trim($link->getAttribute('href'));
        $label = trim($label);

        if ($href === '' || str_starts_with($href, '#') || $href === $label) {
            return $label;
        }

        if (str_starts_with(strtolower($href), 'mailto:')) {
            $address = substr($href, 7);

            return $label === '' || $label === $address ? $address : "{$label} ({$address})";
        }

        return $label === '' ? $href : "{$label} ({$href})";
    }

    /**
     * An element hidden with an inline style, such as a hand-written
     * preheader, which the reader of the HTML never sees either.
     */
    protected static function isHidden(DOMElement $element): bool
    {
        return preg_match('/(^|;)\s*display\s*:\s*none\b/i', $element->getAttribute('style')) === 1;
    }
}
