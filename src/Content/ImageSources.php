<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * Every place an email's HTML names an image to load, read by scanning the
 * source forward rather than through a DOM, so each comes with where it is
 * written. The revision rules, the pre-send checks and an upload's rewrite
 * all read this one list: a path the rewrite points at the asset library
 * is exactly a path counted as used, and one it can't is refused as not on
 * https rather than going out as a 404. A DOM misses what Outlook's
 * conditional comments hold (their inner HTML, which Outlook renders), and
 * libxml stops reading a document nested past its depth limit.
 *
 * What counts: src, srcset and lowsrc on img (and on image, which HTML
 * reads as img), srcset on source (a video's source src is no image), src
 * on an input of type image, poster on video, src on any VML element
 * (v:image, v:fill, v:imagedata), href and xlink:href on SVG's image,
 * background on any element, and in CSS, in style attributes and style
 * elements, url(), the strings of image-set() and @import's string. An img
 * without a src counts, as an image with no address. Ordinary comments,
 * scripts, and the text of title and textarea hold none; neither does
 * url(#id), which points into the document.
 *
 * Where reading less would hide an image, it reads as HTML5 does: a
 * comment ends at <!-->, <!---> or --!> too; an attribute's character
 * references are decoded without their ";" where a browser decodes them
 * (&#47, &#13, &quot followed by a space); a CSS escape (\" or \/) opens
 * no string or comment. Left unread on purpose, since the author is the
 * app's own agent and no honest email writes them: markup inside a style
 * element within svg or math (a browser reads that text as markup),
 * comments inside an iframe's or xmp's text, CSS names written with
 * escapes (\75 rl for url), a string inside var() within image-set(), a
 * condition longer than 256 bytes, and embed, object and feImage.
 *
 * Every search runs forward from where the last one stopped, never one
 * lazy pattern over the rest of the email, so an email of unclosed quotes,
 * tags or url( openers is read in one pass rather than running out of
 * PCRE's backtrack limit and silently reading nothing.
 */
class ImageSources
{
    /**
     * The attributes that name an image, by element. Any element's
     * background and style, and any VML element's src, count besides.
     */
    protected const ATTRIBUTES = [
        'img' => ['src', 'srcset', 'lowsrc'],
        'image' => ['src', 'srcset', 'lowsrc', 'href', 'xlink:href'],
        'source' => ['srcset'],
        'input' => ['src'],
        'video' => ['poster'],
    ];

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
     * The CSS functions whose strings are addresses.
     */
    protected const CSS_ADDRESS_FUNCTIONS = ['url', 'image-set', '-webkit-image-set'];

    /**
     * @return list<ImageSource>
     */
    public static function in(string $html): array
    {
        $sources = [];

        static::markup($html, 0, false, $sources);

        return $sources;
    }

    /**
     * Reads markup; $base is where $html starts in the email, since a
     * conditional comment's inner HTML is read on its own ($inCondition).
     *
     * @param  list<ImageSource>  $sources
     */
    protected static function markup(string $html, int $base, bool $inCondition, array &$sources): void
    {
        $length = strlen($html);
        $at = 0;

        while (($open = strpos($html, '<', $at)) !== false) {
            $next = $html[$open + 1] ?? '';

            if (substr($html, $open, 4) === '<!--') {
                $at = static::comment($html, $open, $base, $inCondition, $sources);
            } elseif (ctype_alpha($next)) {
                $at = static::tag($html, $open, $base, $sources);
            } elseif ($next === '!' || $next === '?' || $next === '/') {
                // A doctype, an end tag or a bogus comment: nothing loads
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
     * @param  list<ImageSource>  $sources
     */
    protected static function comment(string $html, int $open, int $base, bool $inCondition, array &$sources): int
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

        static::markup($endif === false ? $text : substr($text, 0, $endif), $base + $inner, true, $sources);

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
     * Reads a start tag's attributes, and the CSS of a style element after
     * it. Returns where reading goes on: past the tag, or past a raw-text
     * element's text, which runs to the end when it is never closed.
     *
     * @param  list<ImageSource>  $sources
     */
    protected static function tag(string $html, int $open, int $base, array &$sources): int
    {
        $length = strlen($html);
        $at = $open + 1;
        $nameLength = strcspn($html, static::WHITESPACE.'/>', $at);
        $name = strtolower(substr($html, $at, $nameLength));
        $at += $nameLength;
        // Each attribute's value as written, where it starts and its
        // length; a parser keeps the first of a repeated attribute.
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

            [$attribute, $value, $at] = static::attribute($html, $at);

            if (! array_key_exists($attribute, $attributes)) {
                $attributes[$attribute] = $value;
            }
        }

        foreach ($attributes as $attribute => [$offset, $valueLength]) {
            if ($attribute === 'style') {
                static::css($html, $offset, $valueLength, $base, true, $sources);
            } elseif (static::namesImage($name, $attribute, $attributes, $html)) {
                $sources[] = new ImageSource(
                    offset: $base + $offset,
                    length: $valueLength,
                    value: static::decoded(substr($html, $offset, $valueLength)),
                    srcset: $attribute === 'srcset',
                );
            }
        }

        if ($name === 'img' && ! isset($attributes['src'])) {
            $sources[] = new ImageSource($base + $end, 0, '');
        }

        if (! in_array($name, static::RAW_TEXT, true) || $end >= $length) {
            return $end;
        }

        $close = preg_match('#</'.$name.'(?=[\t\n\f\r />]|\z)#i', $html, $match, PREG_OFFSET_CAPTURE, $end) === 1
            ? $match[0][1]
            : $length;

        if ($name === 'style') {
            static::css($html, $end, $close - $end, $base, false, $sources);
        }

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
     * @param  array<string, array{0: int, 1: int}>  $attributes
     */
    protected static function namesImage(string $element, string $attribute, array $attributes, string $html): bool
    {
        if ($attribute === 'background' || ($attribute === 'src' && str_starts_with($element, 'v:'))) {
            return true;
        }

        if (! in_array($attribute, static::ATTRIBUTES[$element] ?? [], true)) {
            return false;
        }

        if ($element !== 'input') {
            return true;
        }

        // An input loads its src only as an image button.
        if (! isset($attributes['type'])) {
            return false;
        }

        [$offset, $length] = $attributes['type'];

        return strtolower(trim(static::decoded(substr($html, $offset, $length)))) === 'image';
    }

    /**
     * Reads the addresses in CSS written at $offset: a style attribute's,
     * whose character references the parser decodes before CSS reads it
     * (url(&quot;...&quot;)), or a style element's, read as written.
     *
     * @param  list<ImageSource>  $sources
     */
    protected static function css(string $html, int $offset, int $length, int $base, bool $inAttribute, array &$sources): void
    {
        $written = substr($html, $offset, $length);
        [$css, $ends, $shifts] = $inAttribute ? static::decodedWithReferences($written) : [$written, [], []];

        foreach (static::cssAddresses($css) as [$start, $end]) {
            // Whitespace inside quotes stays out of the address, so a
            // rewrite keeps the space that ends a bare &quot.
            $start += strspn($css, static::WHITESPACE, $start, $end - $start);
            $end -= strspn(strrev(substr($css, $start, $end - $start)), static::WHITESPACE);
            $value = substr($css, $start, $end - $start);

            if (str_starts_with(trim($value), '#')) {
                continue;
            }

            $from = static::writtenOffset($start, $ends, $shifts);

            $sources[] = new ImageSource(
                offset: $base + $offset + $from,
                length: static::writtenOffset($end, $ends, $shifts) - $from,
                value: $value,
                inAttribute: $inAttribute,
            );
        }
    }

    /**
     * The addresses CSS loads, each as its start and end, read in one pass:
     * url() quoted or not (one left open at the end still loads, as CSS
     * reads it), the strings directly inside image-set() (never those of
     * type() within it), and @import's string. Comments and other strings
     * hold none.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected static function cssAddresses(string $css): array
    {
        $addresses = [];
        $length = strlen($css);
        // The functions open where the scan is, innermost last.
        $functions = [];
        $import = false;
        $at = 0;

        while ($at < $length) {
            $at += strcspn($css, '"\'()/@;{}\\', $at);
            $character = $css[$at] ?? '';

            if ($character === '') {
                break;
            }

            if ($character === '\\') {
                // An escape: the character after it opens no string or
                // comment (--a:\" is an identifier).
                $at += 2;
            } elseif ($character === '/') {
                if (($css[$at + 1] ?? '') === '*') {
                    $close = strpos($css, '*/', $at + 2);
                    $at = $close === false ? $length : $close + 2;
                } else {
                    $at++;
                }
            } elseif ($character === '"' || $character === "'") {
                [$start, $end, $at] = static::cssString($css, $at);

                if ($import || in_array(end($functions), static::CSS_ADDRESS_FUNCTIONS, true)) {
                    $addresses[] = [$start, $end];
                }

                $import = false;
            } elseif ($character === '(') {
                $name = strtolower(static::functionName($css, $at));
                $at++;
                $inner = $at + strspn($css, static::WHITESPACE, $at);

                if ($name !== 'url' || in_array($css[$inner] ?? '', ['"', "'"], true)) {
                    $functions[] = $name;

                    continue;
                }

                // An unquoted url() is one token: its address runs to the
                // closing parenthesis or whitespace.
                $end = $inner + strcspn($css, ')'.static::WHITESPACE, $inner);
                $addresses[] = [$inner, $end];
                $close = strpos($css, ')', $end);
                $at = $close === false ? $length : $close + 1;
                $import = false;
            } elseif ($character === ')') {
                array_pop($functions);
                $at++;
            } elseif ($character === '@') {
                $import = strcasecmp(substr($css, $at + 1, 6), 'import') === 0 && ! static::isNameCharacter($css[$at + 7] ?? ' ');
                $at++;
            } else {
                // A declaration, a block or a rule ended, closing whatever
                // it left open.
                $functions = [];
                $import = false;
                $at++;
            }
        }

        return $addresses;
    }

    /**
     * A CSS string from its opening quote: where its text starts and ends,
     * and where reading goes on. A backslash escapes the next character,
     * and a line break ends the string, as CSS reads it.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected static function cssString(string $css, int $open): array
    {
        $length = strlen($css);
        $quote = $css[$open];
        $at = $open + 1;

        while ($at < $length) {
            $at += strcspn($css, $quote."\\\n\r\f", $at);

            if (($css[$at] ?? '') !== '\\') {
                break;
            }

            $at += 2;
        }

        $end = min($at, $length);

        return [$open + 1, $end, ($css[$end] ?? '') === $quote ? $end + 1 : $end];
    }

    /**
     * The identifier written right before an opening parenthesis.
     */
    protected static function functionName(string $css, int $open): string
    {
        $start = $open;

        while ($start > 0 && static::isNameCharacter($css[$start - 1])) {
            $start--;
        }

        return substr($css, $start, $open - $start);
    }

    protected static function isNameCharacter(string $character): bool
    {
        return ctype_alnum($character) || $character === '-' || $character === '_' || ord($character) >= 0x80;
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
