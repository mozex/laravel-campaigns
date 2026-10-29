<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

/**
 * Adds a campaign's UTM tags to the links in its email that lead to the
 * app's own sites, as a campaign's revision is frozen. A link that already
 * carries any utm_ parameter is left as its author wrote it, and so is one
 * holding a placeholder, which becomes an address only per recipient. Like
 * ImageReferences, it reads each href decoded and changes the source string
 * at its offsets, never through the DOM.
 */
class UtmTags
{
    /**
     * @param  array<string, string>  $tags  by name without the prefix: source, medium, campaign, content, term
     * @param  list<string>  $hosts  a link's host matches one of these or a subdomain of it
     */
    public function __construct(protected array $tags, protected array $hosts) {}

    /**
     * The hosts whose links get the tags: campaigns.utm.hosts, else the
     * hosts of the app's URL and the links' base URL, each without a leading
     * "www." so the app's other subdomains match too.
     *
     * @return list<string>
     */
    public static function configuredHosts(): array
    {
        $hosts = (array) config('campaigns.utm.hosts', []);

        if ($hosts === []) {
            foreach ([config('app.url'), config('campaigns.url')] as $url) {
                $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

                if (is_string($host) && $host !== '') {
                    $hosts[] = preg_replace('/^www\./', '', $host);
                }
            }
        }

        return array_values(array_unique(array_map(fn (mixed $host): string => strtolower(trim((string) $host, " .\t")), $hosts)));
    }

    public function html(string $html): string
    {
        if ($this->tags === []) {
            return $html;
        }

        $changes = [];

        // A quoted attribute may hold a ">", and an unquoted value runs to
        // the next space or ">", "=" included, as browsers read it.
        preg_match_all('/<(?:a|area)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[0] as [$tag, $tagOffset]) {
            // The attributes are read in order, each quoted value whole, so
            // an "href=" inside another attribute's text is never taken for
            // the link.
            preg_match_all('/\s([^\s"\'>\/=]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/', $tag, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $href = array_values(array_filter($attributes, fn (array $attribute): bool => strtolower($attribute[1][0]) === 'href'))[0] ?? null;

            if ($href === null) {
                continue;
            }

            [$raw, $offset] = $href[2];
            $quoted = $raw[0] === '"' || $raw[0] === "'";
            $value = $quoted ? substr($raw, 1, -1) : $raw;
            $tagged = $this->url(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($tagged !== null) {
                $changes[$tagOffset + $offset + ($quoted ? 1 : 0)] = [strlen($value), htmlspecialchars($tagged, ENT_QUOTES | ENT_HTML5, 'UTF-8', false)];
            }
        }

        krsort($changes);

        foreach ($changes as $offset => [$length, $new]) {
            $html = substr_replace($html, $new, $offset, $length);
        }

        return $html;
    }

    /**
     * The same tags on the bare addresses of a plain-text version.
     */
    public function text(string $text): string
    {
        if ($this->tags === []) {
            return $text;
        }

        // The whole run up to a space, so a placeholder, a bracket or a
        // parenthesis inside the address is never cut off from it.
        return (string) preg_replace_callback('/\bhttps?:\/\/\S+/i', function (array $match): string {
            [$url, $tail] = static::splitTrailing($match[0]);

            return ($this->url($url) ?? $url).$tail;
        }, $text);
    }

    /**
     * The address with the tags added, or null when it stays as it is.
     */
    public function url(string $url): ?string
    {
        $url = trim($url);

        if (str_contains($url, '{{') || preg_match('/^https?:\/\//i', $url) !== 1) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! $this->matches(strtolower($host))) {
            return null;
        }

        $hash = strpos($url, '#');
        $fragment = $hash === false ? '' : substr($url, $hash);
        $base = $hash === false ? $url : substr($url, 0, $hash);
        $query = (string) parse_url($base, PHP_URL_QUERY);

        if (preg_match('/(^|&)utm_[^=&]*=/i', $query) === 1) {
            return null;
        }

        $pairs = [];

        foreach ($this->tags as $name => $value) {
            $pairs[] = 'utm_'.$name.'='.rawurlencode($value);
        }

        $separator = match (true) {
            ! str_contains($base, '?') => '?',
            str_ends_with($base, '?') || str_ends_with($base, '&') => '',
            default => '&',
        };

        return $base.$separator.implode('&', $pairs).$fragment;
    }

    /**
     * An address as written in text, and what follows it that isn't part of
     * it: punctuation that ends a sentence, a closing quote or angle
     * bracket, and a closing bracket or parenthesis the address never opened.
     *
     * @return array{0: string, 1: string}
     */
    protected static function splitTrailing(string $url): array
    {
        $tail = '';

        while ($url !== '') {
            $last = substr($url, -1);
            $unopened = ($last === ')' && substr_count($url, '(') < substr_count($url, ')'))
                || ($last === ']' && substr_count($url, '[') < substr_count($url, ']'));

            if (! $unopened && ! str_contains('.,;:!?"\'>', $last)) {
                break;
            }

            $tail = $last.$tail;
            $url = substr($url, 0, -1);
        }

        return [$url, $tail];
    }

    protected function matches(string $host): bool
    {
        foreach ($this->hosts as $allowed) {
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }
}
