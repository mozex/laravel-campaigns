<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Closure;

/**
 * Finds an email's relative image paths (images/hero.png) and swaps them
 * for public addresses. The references are read through the DOM (Html),
 * which decodes them as a browser would, but the change is made in the
 * source string at the matching offsets, since writing the DOM back out
 * would percent-encode the placeholders in every href and src.
 */
class ImageReferences
{
    /**
     * The image addresses that are relative paths: no scheme, not
     * protocol-relative, no placeholder, not a fragment.
     *
     * @return list<string>
     */
    public static function relative(string $html): array
    {
        return array_values(array_unique(array_filter(Html::imageUrls($html), static::isRelative(...))));
    }

    public static function isRelative(string $url): bool
    {
        return $url !== ''
            && ! str_starts_with($url, '#')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '{{')
            && preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) !== 1;
    }

    /**
     * Replaces each image address for which $replace returns a new one, as
     * the DOM decodes it (src, every srcset candidate, background, and CSS
     * url() in style attributes and style elements). Anything $replace
     * returns null for is left byte for byte.
     *
     * @param  Closure(string): ?string  $replace
     */
    public static function rewrite(string $html, Closure $replace): string
    {
        $changes = [];

        preg_match_all('/(?<![\w-])(src|srcset|background)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+)/i', $html, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($attributes as $match) {
            [$raw, $offset] = $match[2];
            $quoted = $raw[0] === '"' || $raw[0] === "'";
            $value = $quoted ? substr($raw, 1, -1) : $raw;
            $decoded = static::decode($value);

            $new = strtolower($match[1][0]) === 'srcset'
                ? static::srcset($decoded, $replace)
                : $replace(trim($decoded));

            if ($new !== null) {
                $changes[$offset + ($quoted ? 1 : 0)] = [strlen($value), htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8', false)];
            }
        }

        // A url() may be quoted with an entity inside a style attribute.
        preg_match_all('/\burl\(\s*(&quot;|&#0*34;|&#x0*22;|&apos;|&#0*39;|&#x0*27;|["\']?)(.*?)\1\s*\)/i', $html, $urls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($urls as $match) {
            [$value, $offset] = $match[2];
            $new = $replace(trim(static::decode($value)));

            if ($new !== null) {
                $changes[$offset] = [strlen($value), $new];
            }
        }

        return static::apply($html, $changes);
    }

    /**
     * @param  Closure(string): ?string  $replace
     */
    protected static function srcset(string $srcset, Closure $replace): ?string
    {
        $changed = false;
        $candidates = [];

        foreach (array_filter(array_map('trim', explode(',', $srcset)), fn (string $candidate): bool => $candidate !== '') as $candidate) {
            $parts = preg_split('/\s+/', $candidate, 2) ?: [$candidate];
            $new = $replace($parts[0]);

            if ($new !== null) {
                $changed = true;
                $parts[0] = $new;
            }

            $candidates[] = implode(' ', $parts);
        }

        return $changed ? implode(', ', $candidates) : null;
    }

    protected static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Applies the changes from the end, so earlier offsets stay right; a
     * change that overlaps one already applied is dropped.
     *
     * @param  array<int, array{0: int, 1: string}>  $changes  by offset: the length replaced and the new text
     */
    protected static function apply(string $html, array $changes): string
    {
        krsort($changes);
        $limit = PHP_INT_MAX;

        foreach ($changes as $offset => [$length, $new]) {
            if ($offset + $length > $limit) {
                continue;
            }

            $html = substr_replace($html, $new, $offset, $length);
            $limit = $offset;
        }

        return $html;
    }
}
