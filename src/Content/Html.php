<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use DOMDocument;
use DOMXPath;

/**
 * Reads an email's HTML. Only ever for reading: libxml writes `{` and `}` in
 * an href or src back out percent-encoded, which would break the
 * placeholders, so a change to the HTML is made in the source string.
 */
class Html
{
    /**
     * How deep elements may nest, the html element counting as one. libxml
     * stops reading a document 256 elements deep and drops everything after
     * that point, so the rules that read the DOM (the plain text, the alt
     * text and link checks) would be blind to the rest; the margin leaves
     * room for the footer the renderer puts where {{footer}} is written.
     */
    public const MAX_DEPTH = 200;

    public static function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        // The XML declaration tells libxml the bytes are UTF-8; without it
        // they are read as Latin-1.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        return $document;
    }

    /**
     * Whether an element sits deeper than MAX_DEPTH.
     */
    public static function nestsTooDeep(string $html): bool
    {
        // Each step goes one level down, so the path finds something only
        // that deep, and libxml walks the tree once.
        $deeper = (new DOMXPath(static::parse($html)))->query(str_repeat('/*', static::MAX_DEPTH + 1));

        return $deeper !== false && $deeper->length > 0;
    }

    /**
     * Every image address the email loads, in every form ImageSources
     * reads, Outlook's conditional comments included.
     *
     * @return list<string>
     */
    public static function imageUrls(string $html): array
    {
        $urls = [];

        foreach (ImageSources::in($html) as $source) {
            foreach ($source->urls() as $url) {
                $urls[] = $url;
            }
        }

        return $urls;
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
