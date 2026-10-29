<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Fetching\Fetcher;
use Mozex\Campaigns\Fetching\FetchTarget;
use Mozex\Campaigns\Fetching\Hop;
use Mozex\Campaigns\Fetching\HostResolver;
use Mozex\Campaigns\Fetching\PublicAddress;
use Psr\Http\Message\RequestInterface;

beforeEach(function (): void {
    Http::preventStrayRequests();
    resolveHosts([
        'example.com' => '93.184.216.34',
        'cdn.example.com' => ['93.184.216.35', '2606:4700::1111'],
        'metadata.example.com' => '169.254.169.254',
        'mixed.example.com' => ['93.184.216.36', '10.0.0.7'],
    ]);
});

describe('public addresses', function (): void {
    it('tells the internet from everything a server reaches on its own', function (string $address, bool $public): void {
        expect(PublicAddress::isPublic($address))->toBe($public);
    })->with([
        ['93.184.216.34', true],
        ['8.8.8.8', true],
        ['2606:4700::1111', true],
        ['127.0.0.1', false],
        ['10.1.2.3', false],
        ['172.16.0.1', false],
        ['192.168.1.1', false],
        ['169.254.169.254', false],
        ['100.64.0.1', false],
        ['0.0.0.0', false],
        ['224.0.0.1', false],
        ['239.255.255.250', false],
        ['255.255.255.255', false],
        ['::1', false],
        ['::', false],
        ['::ffff:127.0.0.1', false],
        ['fe80::1', false],
        ['fd00::1', false],
        ['ff02::1', false],
        ['64:ff9b::7f00:1', false],
        ['64:ff9b:1::a00:1', false],
        ['::ffff:0:7f00:1', false],
        ['fec0::1', false],
        // 127.0.0.1 as an IPv4-compatible address, the way PHP's filter lets it by.
        ['::7f00:1', false],
        ['0:0:0:0:0:0:a9fe:a9fe', false],
        ['not an address', false],
    ]);
});

describe('the guard', function (): void {
    it('pins each hop to the address it checked, sending the host exactly as checked', function (): void {
        $fetcher = app(Fetcher::class);
        $hop = new Hop;

        $target = $fetcher->target('https://CDN.example.com./images/a.png');
        $options = $fetcher->options($target, 1024, $hop);

        expect($target)
            ->host->toBe('cdn.example.com')
            ->port->toBe(443)
            ->address->toBe('93.184.216.35')
            ->and($fetcher->addressOf('https://CDN.example.com./images/a.png?x=1', $target))->toBe('https://cdn.example.com/images/a.png?x=1')
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['proxy'])->toBe('')
            ->and($options['decode_content'])->toBeFalse()
            ->and($options['curl'])->toBe([CURLOPT_RESOLVE => ['cdn.example.com:443:93.184.216.35']])
            ->and($options['progress'](0, 1024, 0, 0))->toBeFalse()
            ->and($options['progress'](0, 1025, 0, 0))->toBeTrue()
            ->and($hop->tooLarge)->toBeTrue()
            ->and((new FetchTarget('example.com', 8443, '2606:4700::1111'))->resolveEntry())->toBe('example.com:8443:[2606:4700::1111]')
            ->and($fetcher->target('http://example.com:8080/', httpsOnly: false)->port)->toBe(8080)
            ->and($fetcher->addressOf('https://[2606:4700::1111]/a', $fetcher->target('https://[2606:4700::1111]/a')))->toBe('https://[2606:4700::1111]/a');
    });

    it('stops at the headers, or at a declared body over the limit', function (): void {
        $fetcher = app(Fetcher::class);
        $target = $fetcher->target('https://example.com/');
        $response = new Response(302, ['Location' => '/next', 'Content-Length' => '2048']);

        $headersOnly = new Hop;
        $body = new Hop;

        expect(fn () => $fetcher->options($target, 1024, $headersOnly, headersOnly: true)['on_headers']($response))->toThrow(CampaignsException::class)
            ->and($headersOnly)
            ->status->toBe(302)
            ->location->toBe('/next')
            ->stoppedAfterHeaders->toBeTrue()
            ->and(fn () => $fetcher->options($target, 1024, $body)['on_headers']($response))->toThrow(CampaignsException::class)
            ->and($body->tooLarge)->toBeTrue();
    });

    it('stops a link at its headers through the client\'s own callbacks, following its redirects', function (): void {
        // Http::fake() never runs the headers callback, while Guzzle's mock
        // handler does, as curl would, so the stop and Laravel's handling of
        // it run for real.
        Http::preventStrayRequests(false);

        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://cdn.example.com/page']),
            new Response(200, [], 'a page nobody reads'),
            new Response(404, [], 'gone'),
        ]);

        Http::globalMiddleware(fn (callable $next): callable => fn (RequestInterface $request, array $options): PromiseInterface => $mock($request, $options));

        $fetcher = app(Fetcher::class);
        $followed = $fetcher->fetch('https://example.com/a', 0, headersOnly: true);

        expect($followed)
            ->url->toBe('https://cdn.example.com/page')
            ->status->toBe(200)
            ->body->toBe('')
            ->and($fetcher->fetch('https://example.com/b', 0, headersOnly: true)->status)->toBe(404)
            ->and($mock->count())->toBe(0);
    });

    it('works with the real HTTP client, connecting only to the pinned address', function (): void {
        // A name that resolves elsewhere, pinned to a closed local port: the
        // connection is refused at once (curl's error 7) instead of reaching
        // the real host, and the client takes every option as given.
        Http::preventStrayRequests(false);

        $options = app(Fetcher::class)->options(new FetchTarget('example.com', 9, '127.0.0.1'), 1024, new Hop, timeout: 4);

        expect(fn () => Http::withOptions($options)->send('GET', 'http://example.com:9/'))
            ->toThrow(ConnectionException::class, 'cURL error 7');
    });

    it('refuses an address the server must never fetch', function (string $url, bool $httpsOnly, string $reason): void {
        expect(fn () => app(Fetcher::class)->target($url, $httpsOnly))
            ->toThrow(CampaignsException::class, $reason);
    })->with([
        'a private address' => ['https://10.0.0.1/', true, 'private or reserved network'],
        'the loopback in IPv6' => ['https://[::1]/', true, 'private or reserved network'],
        'a mapped loopback' => ['https://[::ffff:127.0.0.1]/', true, 'private or reserved network'],
        'the metadata service by name' => ['https://metadata.example.com/', true, 'private or reserved network'],
        'one private address among public ones' => ['https://mixed.example.com/', true, 'private or reserved network'],
        'a number curl reads as an address' => ['https://2130706433/', true, 'no valid host name'],
        'a hexadecimal address' => ['https://0x7f.1/', true, 'no valid host name'],
        'a short dotted address' => ['https://127.1/', true, 'no valid host name'],
        'user details before the host' => ['https://example.com@10.0.0.1/', true, 'user name or password'],
        'a user name' => ['https://user:pass@example.com/', true, 'user name or password'],
        'a backslash' => ['https://example.com\\@10.0.0.1/', true, 'backslashes'],
        'a space' => ['https://example.com /a', true, 'spaces'],
        'http where https is required' => ['http://example.com/', true, 'not on https'],
        'another scheme' => ['ftp://example.com/', false, 'not a web address'],
        'a file' => ['file:///etc/passwd', false, 'not a full web address'],
        'no host' => ['https:///path', true, 'not a full web address'],
        'a name that resolves nowhere' => ['https://nowhere.example.com/', true, 'doesn\'t resolve'],
    ]);

    it('asks the resolver only for host names, never for an address', function (): void {
        app()->instance(HostResolver::class, new class extends HostResolver
        {
            /** @return list<string> */
            public function addresses(string $host): array
            {
                throw new RuntimeException("Looked up {$host}");
            }
        });

        expect(app(Fetcher::class)->target('https://93.184.216.34/')->address)->toBe('93.184.216.34');
    });
});

describe('fetching', function (): void {
    it('follows redirects itself, checking every hop', function (): void {
        Http::fake([
            'https://example.com/start' => Http::response('', 302, ['Location' => '/middle?x=1']),
            'https://example.com/middle?x=1' => Http::response('', 301, ['Location' => 'https://cdn.example.com/end.png']),
            'https://cdn.example.com/end.png' => Http::response('image bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $response = app(Fetcher::class)->fetch('https://EXAMPLE.com./start', 1024);

        expect($response)
            ->url->toBe('https://cdn.example.com/end.png')
            ->status->toBe(200)
            ->contentType->toBe('image/png')
            ->body->toBe('image bytes')
            ->and($response->successful())->toBeTrue();

        Http::assertSentCount(3);
    });

    it('refuses a redirect into a private network before following it', function (string $location): void {
        Http::fake(['https://example.com/start' => Http::response('', 302, ['Location' => $location])]);

        expect(fn () => app(Fetcher::class)->fetch('https://example.com/start', 1024))
            ->toThrow(CampaignsException::class, 'private or reserved network');

        Http::assertSentCount(1);
    })->with([
        'the loopback' => 'https://127.0.0.1/admin',
        'the metadata service' => 'https://169.254.169.254/latest/meta-data/',
        'a name that leads to the metadata service' => 'https://metadata.example.com/',
    ]);

    it('stops after too many redirects', function (): void {
        Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => '/again'])]);

        expect(fn () => app(Fetcher::class)->fetch('https://example.com/start', 1024))
            ->toThrow(CampaignsException::class, 'redirects more than 5 times');

        Http::assertSentCount(Fetcher::MAX_REDIRECTS + 1);
    });

    it('refuses a body over the limit, and sends HEAD without reading one', function (): void {
        Http::fake(['https://example.com/*' => Http::response(str_repeat('x', 1025))]);

        expect(fn () => app(Fetcher::class)->fetch('https://example.com/big', 1024))
            ->toThrow(CampaignsException::class, 'is over 1 KB')
            ->and(app(Fetcher::class)->fetch('https://example.com/big', 1024, 'HEAD')->body)->toBe('');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD');
    });

    it('reports an address that doesn\'t answer', function (): void {
        Http::fake(['https://example.com/*' => Http::failedConnection()]);

        expect(fn () => app(Fetcher::class)->fetch('https://example.com/slow', 1024))
            ->toThrow(CampaignsException::class, 'could not be reached');
    });
});
