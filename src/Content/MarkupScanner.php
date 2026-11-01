<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * Reads an email's HTML by scanning the source forward rather than through
 * a DOM, so what it finds comes with where it is written: the start tags
 * and their attributes, each value with its offset, as a browser's parser
 * reads them. ImageSources and LinkSources read what they need from each
 * tag, so the images and the links of one email are read by the same walk.
 * A DOM misses what Outlook's conditional comments hold (their inner HTML,
 * which Outlook renders), libxml stops reading a document nested past its
 * depth limit, and it writes braces in an href or src percent-encoded.
 *
 * Ordinary comments, and the text of script, style, title and textarea,
 * hold no tags. Where reading less would hide something, it reads as HTML5
 * does: a comment ends at <!-->, <!---> or --!> too, and an attribute's
 * character references are decoded without their ";" where a browser
 * decodes them (&#47, &#13, &quot followed by a space).
 *
 * Every search runs forward from where the last one stopped, never one
 * lazy pattern over the rest of the email, so an email of unclosed quotes
 * or tags is read in one pass rather than running out of PCRE's backtrack
 * limit and silently reading nothing.
 */
abstract class MarkupScanner
{
    /**
     * Elements whose content is text up to their closing tag in every
     * parser outside svg and math, never markup. Others that browsers read
     * as text (iframe, noembed) are read as markup, as older parsers do.
     */
    protected const RAW_TEXT = ['script', 'style', 'title', 'textarea'];

    protected const WHITESPACE = " \t\n\r\f";

    /**
     * The character references HTML5 reads without their ";" that change
     * what CSS or a srcset reads; the others it reads so stand for letters.
     */
    protected const BARE_REFERENCES = ['amp', 'AMP', 'lt', 'LT', 'gt', 'GT', 'quot', 'QUOT'];

    /**
     * What one start tag holds, added to $found: called with the element's
     * name, its attributes (each value as written: where it starts and its
     * length, then where the whole attribute starts and ends; a parser keeps
     * the first of a repeated attribute) and where the tag ends.
     *
     * @param  array<string, array{0: int, 1: int, 2: int, 3: int}>  $attributes
     * @param  list<object>  $found
     */
    abstract protected static function element(string $html, string $name, array $attributes, int $end, int $base, array &$found): void;

    /**
     * What a raw-text element's text holds (a style element's CSS), its
     * text running from $start to $end.
     *
     * @param  list<object>  $found
     */
    protected static function rawText(string $html, string $name, int $start, int $end, int $base, array &$found): void {}

    /**
     * Reads markup; $base is where $html starts in the email, since a
     * conditional comment's inner HTML is read on its own ($inCondition).
     *
     * @param  list<object>  $found
     */
    protected static function markup(string $html, int $base, bool $inCondition, array &$found): void
    {
        $length = strlen($html);
        $at = 0;

        while (($open = strpos($html, '<', $at)) !== false) {
            $next = $html[$open + 1] ?? '';

            if (substr($html, $open, 4) === '<!--') {
                $at = static::comment($html, $open, $base, $inCondition, $found);
            } elseif (ctype_alpha($next)) {
                $at = static::tag($html, $open, $base, $found);
            } elseif ($next === '!' || $next === '?' || $next === '/') {
                // A doctype, an end tag or a bogus comment: nothing is read
                // from it.
                $close = strpos($html, '>', $open + 1);
                $at = $close === false ? $length : $close + 1;
            } else {
                $at = $open + 1;
            }
        }
    }

    /**
     * Skips a comment, reading the inner HTML of Outlook's conditional
     * comments (<!--[if mso]> ... <![endif]-->) as markup. Returns where
     * the markup after it starts.
     *
     * @param  list<object>  $found
     */
    protected static function comment(string $html, int $open, int $base, bool $inCondition, array &$found): int
    {
        $start = $open + 4;

        // The condition is short; reading only the comment's start keeps a
        // long comment without one from being searched to its end. One with
        // no --> or --!> in it can't hold the comment's end.
        $conditional = ! in_array(substr($html, $start, 1), ['>', '-'], true)
            && preg_match('/^\s*\[if\b[^\]]*\]>/i', substr($html, $start, 256), $condition) === 1
            && preg_match('/--!?>/', $condition[0]) !== 1;

        // Inside a condition's text no comment can close before the text
        // ends; how Outlook reads one opened there is unknown, so reading
        // goes on rather than leaving the rest unread.
        if (! $conditional) {
            return $inCondition ? $start : static::commentEnd($html, $start)[1];
        }

        $inner = $start + strlen($condition[0]);

        // Inside a conditional comment's text nothing closes a comment, so a
        // condition opened there is read on in place, its end never looked
        // for: reading each one's text again on its own took 110 MB for
        // 4,000 of them, and looking for each one's end was quadratic.
        if ($inCondition) {
            return $inner;
        }

        [$end, $after] = static::commentClose($html, $inner);
        $text = substr($html, $inner, $end - $inner);
        $endif = strripos($text, '<![endif]');

        static::markup($endif === false ? $text : substr($text, 0, $endif), $base + $inner, true, $found);

        return $after;
    }

    /**
     * Where a comment's text ends and the markup after it starts, its text
     * starting at $start (right after <!--), as HTML5 ends one: at once for
     * <!--> and <!--->, else as commentClose() says.
     *
     * @return array{0: int, 1: int}
     */
    protected static function commentEnd(string $html, int $start): array
    {
        if (($html[$start] ?? '') === '>') {
            return [$start, $start + 1];
        }

        if (substr($html, $start, 2) === '->') {
            return [$start, $start + 2];
        }

        return static::commentClose($html, $start);
    }

    /**
     * Where a comment's text ends and the markup after it starts, searched
     * from $from: at the first --> or --!>, else at the end of the email.
     *
     * @return array{0: int, 1: int}
     */
    protected static function commentClose(string $html, int $from): array
    {
        for ($at = $from; ($dashes = strpos($html, '--', $at)) !== false; $at = $dashes + 1) {
            if (($html[$dashes + 2] ?? '') === '>') {
                return [$dashes, $dashes + 3];
            }

            if (substr($html, $dashes + 2, 2) === '!>') {
                return [$dashes, $dashes + 4];
            }
        }

        return [strlen($html), strlen($html)];
    }

    /**
     * Reads a start tag's attributes, and a raw-text element's text after
     * it. Returns where reading goes on: past the tag, or past a raw-text
     * element's text, which runs to the end when it is never closed.
     *
     * @param  list<object>  $found
     */
    protected static function tag(string $html, int $open, int $base, array &$found): int
    {
        $length = strlen($html);
        $at = $open + 1;
        $nameLength = strcspn($html, static::WHITESPACE.'/>', $at);
        $name = strtolower(substr($html, $at, $nameLength));
        $at += $nameLength;
        $attributes = [];
        $end = $length;

        while ($at < $length) {
            $at += strspn($html, static::WHITESPACE.'/', $at);

            if ($at >= $length) {
                break;
            }

            if ($html[$at] === '>') {
                $end = $at + 1;

                break;
            }

            $start = $at;
            [$attribute, [$offset, $valueLength], $at] = static::attribute($html, $at);

            if (! array_key_exists($attribute, $attributes)) {
                $attributes[$attribute] = [$offset, $valueLength, $start, $at];
            }
        }

        static::element($html, $name, $attributes, $end, $base, $found);

        if (! in_array($name, static::RAW_TEXT, true) || $end >= $length) {
            return $end;
        }

        $close = preg_match('#</'.$name.'(?=[\t\n\f\r />]|\z)#i', $html, $match, PREG_OFFSET_CAPTURE, $end) === 1
            ? $match[0][1]
            : $length;

        static::rawText($html, $name, $end, $close, $base, $found);

        return $close;
    }

    /**
     * One attribute from its name's first character: its name, where its
     * value is written and its length (none is empty), and where the tag
     * goes on.
     *
     * @return array{0: string, 1: array{0: int, 1: int}, 2: int}
     */
    protected static function attribute(string $html, int $at): array
    {
        $length = strlen($html);
        // A name's first character may be "=", as a parser reads <img =a>.
        $nameLength = 1 + strcspn($html, static::WHITESPACE.'/>=', $at + 1);
        $name = strtolower(substr($html, $at, $nameLength));
        $at += $nameLength;
        $equals = $at + strspn($html, static::WHITESPACE, $at);

        if ($equals >= $length || $html[$equals] !== '=') {
            return [$name, [$at, 0], $at];
        }

        $at = $equals + 1;
        $at += strspn($html, static::WHITESPACE, $at);
        $quote = $html[$at] ?? '';

        if ($quote === '"' || $quote === "'") {
            $close = strpos($html, $quote, $at + 1);
            $close = $close === false ? $length : $close;

            return [$name, [$at + 1, $close - $at - 1], min($close + 1, $length)];
        }

        $valueLength = strcspn($html, static::WHITESPACE.'>', $at);

        return [$name, [$at, $valueLength], $at + $valueLength];
    }

    /**
     * An attribute's value as the parser hands it on.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int}  $attribute
     */
    protected static function value(string $html, array $attribute): string
    {
        return static::decoded(substr($html, $attribute[0], $attribute[1]));
    }

    protected static function decoded(string $written): string
    {
        return static::decodedWithReferences($written)[0];
    }

    /**
     * An attribute's text with its character references decoded, as the
     * parser hands it on, with where each reference ends in the decoded
     * text and how much longer the written text is from there, in order, so
     * a decoded offset maps back to the HTML. Two lists of numbers, not a
     * pair per reference, since a style attribute may hold a megabyte of
     * them.
     *
     * @return array{0: string, 1: list<int>, 2: list<int>}
     */
    protected static function decodedWithReferences(string $written): array
    {
        if (! str_contains($written, '&')) {
            return [$written, [], []];
        }

        $decoded = '';
        $ends = [];
        $shifts = [];
        $at = 0;

        while (($ampersand = strpos($written, '&', $at)) !== false) {
            $decoded .= substr($written, $at, $ampersand - $at);
            $reference = static::reference($written, $ampersand);

            if ($reference === null) {
                $decoded .= '&';
                $at = $ampersand + 1;

                continue;
            }

            $decoded .= $reference[1];
            $at = $ampersand + $reference[0];
            $ends[] = strlen($decoded);
            $shifts[] = $at - strlen($decoded);
        }

        return [$decoded.substr($written, $at), $ends, $shifts];
    }

    /**
     * The character reference at an ampersand, as HTML5 reads one in an
     * attribute: a number with or without its ";", a name with it, or,
     * without it, one of the names that would change what CSS or a srcset
     * reads (&quot, &amp, &lt, &gt) when no "=" follows. Its length as
     * written and its character, or null when there is none.
     *
     * @return array{0: int, 1: string}|null
     */
    protected static function reference(string $written, int $ampersand): ?array
    {
        $at = $ampersand + 1;

        if (($written[$at] ?? '') === '#') {
            $hex = in_array($written[$at + 1] ?? '', ['x', 'X'], true);
            $digitsAt = $at + ($hex ? 2 : 1);
            $digits = strspn($written, $hex ? '0123456789abcdefABCDEF' : '0123456789', $digitsAt);

            if ($digits === 0) {
                return null;
            }

            $end = $digitsAt + $digits;

            return [$end - $ampersand + (($written[$end] ?? '') === ';' ? 1 : 0), static::numbered(substr($written, $digitsAt, $digits), $hex)];
        }

        $nameLength = strspn($written, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $at);
        $name = substr($written, $at, $nameLength);
        $next = $written[$at + $nameLength] ?? '';

        if ($nameLength === 0 || ($next !== ';' && ($next === '=' || ! in_array($name, static::BARE_REFERENCES, true)))) {
            return null;
        }

        $character = html_entity_decode("&{$name};", ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $character === "&{$name};" ? null : [$nameLength + ($next === ';' ? 2 : 1), $character];
    }

    /**
     * A numeric reference's character as HTML5 gives it. PHP's decoder
     * leaves control characters as written, yet a browser reads &#13 as a
     * carriage return, which ends a CSS string; a code point no character
     * has becomes U+FFFD.
     */
    protected static function numbered(string $digits, bool $hex): string
    {
        $reference = '&#'.($hex ? 'x' : '').$digits.';';
        $character = html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($character !== $reference) {
            return $character;
        }

        $significant = ltrim($digits, '0');

        // Past seven digits a number is beyond the last code point (and an
        // integer's range), whichever base.
        if (strlen($significant) > 7) {
            return "\u{FFFD}";
        }

        $code = (int) ($hex ? hexdec($significant ?: '0') : $significant);

        return $code === 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)
            ? "\u{FFFD}"
            : (string) mb_chr($code, 'UTF-8');
    }

    /**
     * Where a decoded offset is in the text as written: shifted by the last
     * reference ending at or before it.
     *
     * @param  list<int>  $ends
     * @param  list<int>  $shifts
     */
    protected static function writtenOffset(int $decoded, array $ends, array $shifts): int
    {
        $low = 0;
        $high = count($ends) - 1;
        $shift = 0;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($ends[$middle] <= $decoded) {
                $shift = $shifts[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $decoded + $shift;
    }
}
