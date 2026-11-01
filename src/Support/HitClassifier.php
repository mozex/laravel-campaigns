<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

/**
 * Which machine, if any, made a hit on an email's open pixel or one of its
 * tracked links, by the request's method and user agent alone, as the hit
 * arrives. Only the answer is kept, never the agent or the IP: IPs can't
 * tell people from machines (Apple's prefetch leaves through networks
 * shared with iCloud Private Relay, which carries real people's clicks).
 * The timing rules, an open or click right as the email arrived, are
 * ApplyFeedbackAction's, applied once the delivery has had time to land.
 *
 * The answers: apple, Apple Mail's privacy prefetch, which sends exactly
 * "Mozilla/5.0" whether or not the person reads; gmail and yahoo, the image
 * proxies Gmail and Yahoo Mail fetch through only when the email is opened,
 * so a person (RecordFeedbackAction::HUMAN_PROXIES); generic, any other
 * machine: a HEAD request (security software checks links that way), no
 * agent, an HTTP library, a mail security gateway or crawler by name, and the
 * Chrome 42 on Edge 12 agent prefetchers use. Null is a person. No desktop
 * browser's agent is matched, and a phone whose model name ends in "bot"
 * isn't a crawler.
 */
class HitClassifier
{
    /**
     * Words in a user agent that name an HTTP library, a mail security
     * gateway, an automated browser, or a chat app fetching a link's preview
     * (a tracked link shared in a chat would otherwise count as the
     * recipient's click). Not okhttp: Android mail apps may load images
     * with it.
     */
    public const MACHINES = [
        'curl/', 'Wget/', 'python-requests', 'Python-urllib', 'python-httpx', 'aiohttp/', 'Go-http-client',
        'Java/', 'Apache-HttpClient', 'libwww-perl', 'node-fetch', 'axios/', 'undici', 'GuzzleHttp',
        'Symfony HttpClient', 'HeadlessChrome', 'PhantomJS', 'Mimecast', 'Proofpoint', 'Barracuda',
        'facebookexternalhit', 'TelegramBot', 'WhatsApp/', 'SkypeUriPreview',
    ];

    /**
     * The agent Apple's and Gmail's prefetchers have been seen sending, its
     * version numbers pinned to what no real browser carries together.
     */
    public const PREFETCHER = 'Chrome/42.0.2311.135 Safari/537.36 Edge/12.246';

    public static function machine(string $method, ?string $userAgent): ?string
    {
        $agent = trim((string) $userAgent);

        return match (true) {
            strtoupper($method) === 'HEAD', $agent === '' => 'generic',
            $agent === 'Mozilla/5.0' => 'apple',
            str_contains($agent, 'GoogleImageProxy') => 'gmail',
            str_contains($agent, 'YahooMailProxy') => 'yahoo',
            str_contains($agent, static::PREFETCHER), static::namesMachine($agent) => 'generic',
            default => null,
        };
    }

    /**
     * A library or gateway by name, or a crawler: "bot", "crawler" or
     * "spider" as a word of its own, or ending a name that a version or a
     * suffix follows (Googlebot/2.1, Slackbot-LinkExpanding), never a word
     * that merely ends in it (a phone's "CUBOT X50").
     */
    protected static function namesMachine(string $agent): bool
    {
        foreach (static::MACHINES as $name) {
            if (stripos($agent, $name) !== false) {
                return true;
            }
        }

        return preg_match('#\b(?:bot|crawler|spider)\b|[a-z](?:bot|crawler|spider)[/-]#i', $agent) === 1;
    }
}
