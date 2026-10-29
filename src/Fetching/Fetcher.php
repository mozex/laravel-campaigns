<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mozex\Campaigns\CampaignsException;
use Psr\Http\Message\ResponseInterface;

/**
 * Every request the server makes to an address an agent gave (a link in an
 * email, an image to add) goes through here. Each hop, redirects included,
 * must name a host whose every address is public; the request goes to the
 * host exactly as checked, its connection pinned to the checked address, so
 * DNS can't answer differently a moment later and send it into the
 * server's own network; and no proxy is used, since a proxy resolves the
 * host itself. Redirects are followed here, never by the client, so each
 * one passes the same guard.
 */
class Fetcher
{
    public const MAX_REDIRECTS = 5;

    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 10;

    protected const USER_AGENT = 'Mozilla/5.0 (compatible; laravel-campaigns)';

    /**
     * Lower-case labels of letters, digits, dashes and underscores, ending
     * in a name that starts with a letter, so a number such as 2130706433
     * or 0x7f.1, which curl would read as an IP address, is never a host.
     */
    protected const HOST_NAME = '/^(?=.{1,253}\z)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    public function __construct(protected HostResolver $resolver) {}

    /**
     * A GET or HEAD of the address and its redirects. With $headersOnly, a
     * GET stops as soon as the headers arrive, reading no body: enough to
     * know a link works. Otherwise the body is read, up to $maxBytes.
     *
     * @param  'GET'|'HEAD'  $method
     * @param  ?float  $deadline  a microtime(true) moment the whole fetch must end by
     *
     * @throws CampaignsException when the address isn't allowed, can't be
     *                            reached, redirects too often or sends more than $maxBytes
     */
    public function fetch(
        string $url,
        int $maxBytes,
        string $method = 'GET',
        bool $httpsOnly = true,
        bool $headersOnly = false,
        ?float $deadline = null,
    ): FetchedResponse {
        $current = $url;

        for ($redirects = 0; ; $redirects++) {
            $target = $this->target($current, $httpsOnly);
            $hop = new Hop;
            $timeout = static::TIMEOUT;

            if ($deadline !== null) {
                $timeout = (int) min($timeout, floor($deadline - microtime(true)));

                if ($timeout < 1) {
                    throw CampaignsException::cannotFetch($current, 'was not checked: the time for checking ran out');
                }
            }

            try {
                $response = Http::withOptions($this->options($target, $maxBytes, $hop, $headersOnly, $timeout))
                    ->withUserAgent(static::USER_AGENT)
                    ->withHeaders(['Accept-Encoding' => 'identity'])
                    ->send($method, $this->addressOf($current, $target));

                $status = $response->status();
                $location = $response->header('Location');
            } catch (HttpClientException|TransferException $exception) {
                if ($hop->tooLarge) {
                    throw CampaignsException::cannotFetch($current, 'is over '.static::size($maxBytes));
                }

                // A stop the fetcher asked for once the headers were in: the
                // address answered, and they hold all that is needed.
                if (! $hop->stoppedAfterHeaders || $hop->status === null) {
                    throw CampaignsException::cannotFetch($current, $exception instanceof ConnectionException
                        ? 'could not be reached, or did not answer in time'
                        : 'could not be read: the transfer failed');
                }

                $response = null;
                $status = $hop->status;
                $location = $hop->location;
            }

            if ($status >= 300 && $status < 400 && $location !== '') {
                if ($redirects === static::MAX_REDIRECTS) {
                    throw CampaignsException::cannotFetch($url, 'redirects more than '.static::MAX_REDIRECTS.' times');
                }

                try {
                    $current = (string) UriResolver::resolve(Utils::uriFor($current), Utils::uriFor($location));
                } catch (InvalidArgumentException) {
                    throw CampaignsException::cannotFetch($current, 'redirects to an address that can\'t be read');
                }

                continue;
            }

            if ($response === null || $method === 'HEAD' || $headersOnly) {
                return new FetchedResponse($current, $status, $response?->header('Content-Type') ?? '');
            }

            // Read only up to the limit, so an oversized body that got past
            // the transfer's own limits is never loaded into memory whole.
            $stream = $response->toPsrResponse()->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $body = Utils::copyToString($stream, $maxBytes + 1);

            if (strlen($body) > $maxBytes) {
                throw CampaignsException::cannotFetch($current, 'is over '.static::size($maxBytes));
            }

            return new FetchedResponse($current, $status, $response->header('Content-Type'), $body);
        }
    }

    /**
     * The client's options for one hop: no redirects followed by the
     * client, no proxy, the connection pinned to the checked address, and
     * the transfer stopped by the headers callback, once the headers are in
     * ($headersOnly) or when they declare a body over the limit, and by the
     * progress callback once the bytes pass it (Guzzle 8; Guzzle 7 ignores
     * its answer, so there a body without a declared length runs to the
     * timeout into a temporary file, of which fetch() reads only the limit).
     *
     * @return array<string, mixed>
     */
    public function options(FetchTarget $target, int $maxBytes, Hop $hop, bool $headersOnly = false, int $timeout = self::TIMEOUT): array
    {
        return [
            'allow_redirects' => false,
            'connect_timeout' => min(static::CONNECT_TIMEOUT, $timeout),
            'timeout' => $timeout,
            // The body as sent (asked for uncompressed): a compressed one would
            // be counted small and expand past the limit once decoded, and
            // Guzzle 7 hides its declared length from the headers callback.
            'decode_content' => false,
            // An empty proxy, which every Guzzle 7 and 8 turns into curl's
            // "no proxy, whatever the environment says". A "no" list would
            // let Guzzle 7.9 and older drop the setting and curl read the
            // environment's proxy.
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => [$target->resolveEntry()],
            ],
            'on_headers' => function (ResponseInterface $response) use ($hop, $maxBytes, $headersOnly): void {
                // Guzzle 7 reports an interim response (103 Early Hints) too;
                // the answer is the one after it.
                if ($response->getStatusCode() < 200) {
                    return;
                }

                $hop->status = $response->getStatusCode();
                $hop->location = $response->getHeaderLine('Location');

                if ($headersOnly) {
                    $hop->stoppedAfterHeaders = true;

                    throw CampaignsException::cannotFetch('', 'stopped once its headers arrived');
                }

                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    $hop->tooLarge = true;

                    throw CampaignsException::cannotFetch('', 'is over the size limit');
                }
            },
            'progress' => function (int|float $downloadTotal, int|float $downloaded) use ($hop, $maxBytes): bool {
                $hop->tooLarge = $hop->tooLarge || $downloaded > $maxBytes;

                return $hop->tooLarge;
            },
        ];
    }

    /**
     * Where one hop goes, once its address passes the guard.
     *
     * @throws CampaignsException
     */
    public function target(string $url, bool $httpsOnly = true): FetchTarget
    {
        // curl and PHP read some malformed addresses differently (a
        // backslash, a space, user details before the host), which could
        // send curl to a host the guard never checked.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw CampaignsException::cannotFetch($url, 'holds spaces, control characters or backslashes');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw CampaignsException::cannotFetch($url, 'is not a full web address');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw CampaignsException::cannotFetch($url, 'carries a user name or password');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, $httpsOnly ? ['https'] : ['http', 'https'], true)) {
            throw CampaignsException::cannotFetch($url, $httpsOnly ? 'is not on https' : 'is not a web address');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $host = strtolower(rtrim($parts['host'], '.'));
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->checked($url, $literal, $port, [$literal]);
        }

        if (preg_match('/[^\x21-\x7E]/', $host) === 1) {
            // Non-transitional, as browsers convert: faß.de stays faß.de
            // rather than becoming fass.de, another site.
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                throw CampaignsException::cannotFetch($url, 'has a host name that can\'t be read; write it in its xn-- form');
            }

            $host = $ascii;
        }

        if (preg_match(static::HOST_NAME, $host) !== 1) {
            throw CampaignsException::cannotFetch($url, 'has no valid host name');
        }

        return $this->checked($url, $host, $port, $this->resolver->addresses($host));
    }

    /**
     * The address as sent: with the host exactly as checked and pinned, so
     * a trailing dot or a Unicode name can't make curl look the host up
     * again by itself.
     */
    public function addressOf(string $url, FetchTarget $target): string
    {
        if (filter_var($target->host, FILTER_VALIDATE_IP) !== false) {
            return $url;
        }

        return (string) Utils::uriFor($url)->withHost($target->host);
    }

    /**
     * @param  list<string>  $addresses
     *
     * @throws CampaignsException
     */
    protected function checked(string $url, string $host, int $port, array $addresses): FetchTarget
    {
        if ($addresses === []) {
            throw CampaignsException::cannotFetch($url, 'has a host name that doesn\'t resolve');
        }

        foreach ($addresses as $address) {
            // The address itself isn't named: the results reach whoever runs
            // the checks, and would map the server's own network for them.
            if (! PublicAddress::isPublic($address)) {
                throw CampaignsException::cannotFetch($url, 'leads into a private or reserved network, which the server never fetches from');
            }
        }

        return new FetchTarget($host, $port, $addresses[0]);
    }

    protected static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / 1024 / 1024, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
