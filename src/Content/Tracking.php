<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;

/**
 * The package's own open and click tracking, put into one recipient's
 * rendered HTML as it is sent, never into the renderer: the web version,
 * the preview, the checks' copy and the plain text stay untracked. Every
 * link LinkSources finds whose address is one of its revision's tracked
 * addresses is wrapped in a redirect, and the open pixel goes right after
 * the hidden preheader.
 *
 * Which addresses are tracked is read from the revision's HTML as written
 * (urls()), the same rule that makes its CampaignLink rows, so a link whose
 * address held a placeholder matches no row once filled in and is never
 * wrapped. Never wrapped either: an address that isn't http(s), the
 * package's own pages, a link marked data-campaigns-no-track (the attribute
 * is taken out of every email), a link whose visible text is itself an
 * address (a reader sees where it leads), and an address longer than
 * MAX_URL_LENGTH.
 */
class Tracking
{
    /**
     * The longest address a link is wrapped for; a longer one goes out as
     * written. A redirect carries it in its Location header, and web servers'
     * default header buffers (nginx's 4 or 8 KB for a FastCGI or proxied
     * answer) refuse much more.
     */
    public const MAX_URL_LENGTH = 2048;

    /**
     * @return list<string> the addresses a revision's links lead to, as written, that its emails track
     */
    public static function urls(string $html): array
    {
        $urls = [];

        foreach (LinkSources::in($html) as $link) {
            if (static::tracks($link)) {
                $urls[] = $link->url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The rendered HTML with its links wrapped (when $clicks, for the
     * addresses in $keys) and its open pixel (when $opens), for the email
     * whose tracking token this is, and every no-track mark taken out.
     *
     * @param  array<string, string>  $keys  each tracked address's CampaignLink key
     */
    public static function apply(string $html, array $keys, string $token, bool $opens, bool $clicks): string
    {
        $changes = [];

        foreach (LinkSources::in($html) as $link) {
            if ($link->noTrack !== null) {
                $changes[] = [$link->noTrack[0], $link->noTrack[1] - $link->noTrack[0], ''];
            }

            if ($clicks && isset($keys[$link->url]) && static::tracks($link)) {
                $changes[] = [$link->offset, $link->length, htmlspecialchars(Links::click($keys[$link->url], $token), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            }
        }

        if ($opens) {
            $changes[] = [static::outside(Renderer::pixelOffset($html), $changes), 0, static::pixel($token)];
        }

        // In order, built in one pass: a copy of the whole email per change
        // would cost tens of megabytes for one with hundreds of links. At one
        // offset the pixel, which replaces nothing, goes before a replacement.
        usort($changes, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $tracked = '';
        $at = 0;

        foreach ($changes as [$offset, $length, $text]) {
            $tracked .= substr($html, $at, $offset - $at).$text;
            $at = $offset + $length;
        }

        return $tracked.substr($html, $at);
    }

    /**
     * The rendered HTML with no tracking, only its no-track marks taken
     * out, as the web version shows it.
     */
    public static function none(string $html): string
    {
        return static::apply($html, [], '', opens: false, clicks: false);
    }

    /**
     * How long the HTML is once tracked, measured with a key and a token of
     * the real lengths: what the 90 KB rule and the size check count.
     *
     * @param  string  $written  the revision's HTML as written, which says which addresses are tracked
     */
    public static function trackedLength(string $written, string $rendered, bool $opens = true, bool $clicks = true): int
    {
        $keys = array_fill_keys(static::urls($written), str_repeat('x', CampaignLink::KEY_LENGTH));

        return strlen(static::apply($rendered, $keys, str_repeat('x', strlen(Token::generate())), $opens, $clicks));
    }

    /**
     * The offset moved past any change it falls inside, so the pixel never
     * goes into the middle of a mark being taken out (markup that ends a
     * comment where the renderer's body search doesn't).
     *
     * @param  list<array{0: int, 1: int, 2: string}>  $changes
     */
    protected static function outside(int $offset, array $changes): int
    {
        foreach ($changes as [$start, $length]) {
            if ($offset > $start && $offset < $start + $length) {
                return $start + $length;
            }
        }

        return $offset;
    }

    protected static function pixel(string $token): string
    {
        return '<img src="'.htmlspecialchars(Links::open($token), ENT_QUOTES | ENT_HTML5, 'UTF-8').'" width="1" height="1" alt="" style="display:block;border:0">';
    }

    protected static function tracks(LinkSource $link): bool
    {
        return $link->noTrack === null
            && preg_match('#^https?://#i', $link->url) === 1
            && ! str_contains($link->url, '{{')
            && strlen($link->url) <= static::MAX_URL_LENGTH
            && ! Links::isPackagePage($link->url)
            && ! $link->showsAddress();
    }
}
