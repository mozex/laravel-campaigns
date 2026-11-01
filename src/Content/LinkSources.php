<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * Every link in an email's HTML, each with where its href is written, read
 * by the same forward walk as ImageSources, never a DOM: UTM tagging, the
 * tracked links, their rewriting and the pre-send link and size checks all
 * read this one list, so a VML button in Outlook's conditional comments and
 * its fallback link are tagged, wrapped and counted alike.
 *
 * What counts: href on a and area, and on any VML element (v:roundrect,
 * v:rect), which is how a button links in Outlook. Ordinary comments,
 * scripts, and the text of style, title and textarea hold none.
 *
 * An a element's visible text runs to the next </a or <a, as a parser
 * closes an open link when another starts; searching only that far keeps an
 * email of thousands of unclosed links a single pass.
 */
class LinkSources extends MarkupScanner
{
    /** The attribute that keeps a link out of click tracking. */
    public const NO_TRACK = 'data-campaigns-no-track';

    /**
     * @return list<LinkSource>
     */
    public static function in(string $html): array
    {
        $links = [];

        static::markup($html, 0, false, $links);

        /** @var list<LinkSource> $links */
        return $links;
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int, 3: int}>  $attributes
     * @param  list<object>  $found
     */
    protected static function element(string $html, string $name, array $attributes, int $end, int $base, array &$found): void
    {
        if (! isset($attributes['href']) || (! in_array($name, ['a', 'area'], true) && ! str_starts_with($name, 'v:'))) {
            return;
        }

        $href = $attributes['href'];
        $noTrack = $attributes[static::NO_TRACK] ?? null;

        $found[] = new LinkSource(
            offset: $base + $href[0],
            length: $href[1],
            // As a browser reads an address: tabs and line breaks inside it
            // dropped, which a redirect's Location header couldn't carry.
            url: str_replace(["\t", "\n", "\r"], '', trim(static::value($html, $href), static::WHITESPACE)),
            element: $name,
            text: $name === 'a' ? static::text($html, $end) : null,
            noTrack: $noTrack === null ? null : [$base + $noTrack[2], $base + $noTrack[3]],
        );
    }

    /**
     * An a element's visible text from the end of its start tag: its tags
     * removed and its references decoded, trimmed.
     */
    protected static function text(string $html, int $from): string
    {
        $close = preg_match('#</?a(?=[\s/>])#i', $html, $match, PREG_OFFSET_CAPTURE, $from) === 1 ? $match[0][1] : strlen($html);

        return trim(html_entity_decode(strip_tags(substr($html, $from, $close - $from)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), static::WHITESPACE);
    }
}
