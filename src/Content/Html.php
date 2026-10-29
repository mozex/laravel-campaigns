<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use DOMDocument;
use DOMElement;

/**
 * Reads an email's HTML. Only ever for reading: libxml writes `{` and `}` in
 * an href or src back out percent-encoded, which would break the
 * placeholders, so a change to the HTML is made in the source string.
 */
class Html
{
    public static function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        // The XML declaration tells libxml the bytes are UTF-8; without it
        // they are read as Latin-1.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        return $document;
    }

    /**
     * Every image address the email loads: img src and srcset, background
     * attributes, and CSS url() in style attributes and style elements.
     *
     * @return list<string>
     */
    public static function imageUrls(string $html): array
    {
        $document = static::parse($html);
        $urls = [];

        foreach ($document->getElementsByTagName('img') as $image) {
            $urls[] = $image->getAttribute('src');

            foreach (array_filter(explode(',', $image->getAttribute('srcset'))) as $candidate) {
                $urls[] = (string) preg_split('/\s+/', trim($candidate))[0];
            }
        }

        // CSS is read where CSS lives, decoded by the DOM: a style attribute
        // may quote its url() with &quot;, and body text or an SVG's
        // fill="url(#id)" holds no image.
        $css = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            if ($element->hasAttribute('background')) {
                $urls[] = $element->getAttribute('background');
            }

            if ($element->hasAttribute('style')) {
                $css[] = $element->getAttribute('style');
            }
        }

        foreach ($document->getElementsByTagName('style') as $style) {
            $css[] = $style->textContent;
        }

        preg_match_all('/\burl\(\s*([\'"]?)(.*?)\1\s*\)/i', implode("\n", $css), $matches);

        return array_map(fn (string $url): string => trim($url), [...$urls, ...$matches[2]]);
    }
}
