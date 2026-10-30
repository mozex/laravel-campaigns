<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Closure;

/**
 * Finds an email's relative image paths (images/hero.png) and swaps them
 * for public addresses. Both read ImageSources, the list the revision rules
 * check too, so every path found is a path swapped. The change is made in
 * the source string at the offsets it gives, since writing a DOM back out
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
     * a mail app decodes it (every srcset candidate on its own). Anything
     * $replace returns null for is left byte for byte.
     *
     * @param  Closure(string): ?string  $replace
     */
    public static function rewrite(string $html, Closure $replace): string
    {
        $changes = [];

        foreach (ImageSources::in($html) as $source) {
            // An img without a src names no address to replace.
            if (trim($source->value) === '') {
                continue;
            }

            $new = $source->srcset ? static::srcset($source->value, $replace) : $replace(trim($source->value));

            if ($new !== null) {
                // Inside an attribute, the address is written as attribute
                // text; a style element's CSS is never decoded.
                $changes[$source->offset] = [$source->length, $source->inAttribute ? htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) : $new];
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

        foreach (Html::srcset($srcset) as ['url' => $url, 'descriptor' => $descriptor]) {
            $new = $replace($url);

            if ($new !== null) {
                $changed = true;
                $url = $new;
            }

            $candidates[] = trim("{$url} {$descriptor}");
        }

        return $changed ? implode(', ', $candidates) : null;
    }

    /**
     * Applies the changes in one pass from the start, the HTML between them
     * copied once; replacing them one by one copied the whole email for
     * each, a second per megabyte of thousands of references. A change that
     * overlaps one already applied is dropped.
     *
     * @param  array<int, array{0: int, 1: string}>  $changes  by offset: the length replaced and the new text
     */
    protected static function apply(string $html, array $changes): string
    {
        ksort($changes);
        $parts = [];
        $at = 0;

        foreach ($changes as $offset => [$length, $new]) {
            if ($offset < $at) {
                continue;
            }

            $parts[] = substr($html, $at, $offset - $at);
            $parts[] = $new;
            $at = $offset + $length;
        }

        $parts[] = substr($html, $at);

        return implode('', $parts);
    }
}
