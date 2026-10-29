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

            foreach (static::srcset($image->getAttribute('srcset')) as $candidate) {
                $urls[] = $candidate['url'];
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

        // A url(#id) points into the document (Outlook's VML behavior, an
        // SVG filter) and loads nothing.
        $loaded = array_filter($matches[2], fn (string $url): bool => ! str_starts_with(trim($url), '#'));

        return array_map(fn (string $url): string => trim($url), [...$urls, ...$loaded]);
    }

    /**
     * A srcset's image candidates, read as the HTML spec reads them: an
     * address runs to the next whitespace, commas inside it included (an
     * image service's w_400,h_300), and only trailing commas end it; the
     * descriptor runs to the next comma.
     *
     * @return list<array{url: string, descriptor: string}>
     */
    public static function srcset(string $srcset): array
    {
        $candidates = [];
        $length = strlen($srcset);
        $at = 0;

        while ($at < $length) {
            $at += strspn($srcset, " \t\n\r\f,", $at);

            if ($at >= $length) {
                break;
            }

            $url = substr($srcset, $at, strcspn($srcset, " \t\n\r\f", $at));
            $at += strlen($url);
            $descriptor = '';

            if (str_ends_with($url, ',')) {
                $url = rtrim($url, ',');
            } else {
                $descriptor = substr($srcset, $at, strcspn($srcset, ',', $at));
                $at += strlen($descriptor);
            }

            $candidates[] = ['url' => $url, 'descriptor' => trim($descriptor)];
        }

        return $candidates;
    }
}
