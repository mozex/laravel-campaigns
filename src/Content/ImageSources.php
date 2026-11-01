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
class ImageSources extends MarkupScanner
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

        /** @var list<ImageSource> $sources */
        return $sources;
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int, 3: int}>  $attributes
     * @param  list<object>  $found
     */
    protected static function element(string $html, string $name, array $attributes, int $end, int $base, array &$found): void
    {
        foreach ($attributes as $attribute => [$offset, $valueLength]) {
            if ($attribute === 'style') {
                static::css($html, $offset, $valueLength, $base, true, $found);
            } elseif (static::namesImage($name, $attribute, $attributes, $html)) {
                $found[] = new ImageSource(
                    offset: $base + $offset,
                    length: $valueLength,
                    value: static::decoded(substr($html, $offset, $valueLength)),
                    srcset: $attribute === 'srcset',
                );
            }
        }

        if ($name === 'img' && ! isset($attributes['src'])) {
            $found[] = new ImageSource($base + $end, 0, '');
        }
    }

    /**
     * A style element's CSS.
     *
     * @param  list<object>  $found
     */
    protected static function rawText(string $html, string $name, int $start, int $end, int $base, array &$found): void
    {
        if ($name === 'style') {
            static::css($html, $start, $end - $start, $base, false, $found);
        }
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int, 3: int}>  $attributes
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

        return strtolower(trim(static::value($html, $attributes['type']))) === 'image';
    }

    /**
     * Reads the addresses in CSS written at $offset: a style attribute's,
     * whose character references the parser decodes before CSS reads it
     * (url(&quot;...&quot;)), or a style element's, read as written.
     *
     * @param  list<object>  $sources
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
}
