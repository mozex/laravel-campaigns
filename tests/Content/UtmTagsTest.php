<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\UtmTags;

it('tags only http links to the app\'s sites that carry no UTM tag yet', function (string $url, ?string $tagged): void {
    $tags = new UtmTags(['source' => 'news', 'campaign' => 'spring sale'], ['example.com']);

    expect($tags->url($url))->toBe($tagged);
})->with([
    'the site' => ['https://example.com', 'https://example.com?utm_source=news&utm_campaign=spring%20sale'],
    'a subdomain, with a query and a fragment' => ['http://shop.example.com/a?b=1#c', 'http://shop.example.com/a?b=1&utm_source=news&utm_campaign=spring%20sale#c'],
    'a query ending in &' => ['https://example.com/?a=1&', 'https://example.com/?a=1&utm_source=news&utm_campaign=spring%20sale'],
    'a host in capitals' => ['https://WWW.Example.com/', 'https://WWW.Example.com/?utm_source=news&utm_campaign=spring%20sale'],
    'its own tag' => ['https://example.com/?UTM_medium=x', null],
    'a look-alike host' => ['https://notexample.com/', null],
    'a host ending in the site' => ['https://example.com.evil.org/', null],
    'another site' => ['https://other.org/', null],
    'a placeholder' => ['{{web_version_url}}', null],
    'a mail link' => ['mailto:hi@example.com', null],
    'a relative link' => ['/pricing', null],
]);

it('reads the default hosts from the app\'s addresses', function (): void {
    config()->set('app.url', 'https://www.example.com');
    config()->set('campaigns.url', 'https://links.example.org/');

    expect(UtmTags::configuredHosts())->toBe(['example.com', 'links.example.org']);

    config()->set('campaigns.utm.hosts', ['Shop.Example.net.']);

    expect(UtmTags::configuredHosts())->toBe(['shop.example.net']);
});

it('leaves the rest of the HTML byte for byte', function (): void {
    $tags = new UtmTags(['source' => 'news'], ['example.com']);
    $html = "<link href=\"https://example.com/a.css\"><A class='x' HREF='https://example.com/?a=1&amp;b=2'>Go</A>\n<a href=https://example.com/b>B</a> <img src=\"https://example.com/i.png\">";

    expect($tags->html($html))->toBe(
        "<link href=\"https://example.com/a.css\"><A class='x' HREF='https://example.com/?a=1&amp;b=2&amp;utm_source=news'>Go</A>\n<a href=https://example.com/b?utm_source=news>B</a> <img src=\"https://example.com/i.png\">"
    )
        ->and($tags->text('See https://example.com/b, or (https://example.com/c).'))
        ->toBe('See https://example.com/b?utm_source=news, or (https://example.com/c?utm_source=news).')
        ->and((new UtmTags([], ['example.com']))->html($html))->toBe($html);
});

it('never cuts an address short', function (): void {
    $tags = new UtmTags(['source' => 'news'], ['example.com']);

    expect($tags->text("Join: https://example.com/join?email={{email}}\nLevels: https://example.com/c?f[level]=1 and https://example.com/wiki/Foo_(bar)."))
        ->toBe("Join: https://example.com/join?email={{email}}\nLevels: https://example.com/c?f[level]=1&utm_source=news and https://example.com/wiki/Foo_(bar)?utm_source=news.")
        ->and($tags->text('<https://example.com/a>'))->toBe('<https://example.com/a?utm_source=news>')
        ->and($tags->html('<a href=https://example.com/?a=1>A</a> <a title="a>b" href="https://example.com/b">B</a>'))
        ->toBe('<a href=https://example.com/?a=1&amp;utm_source=news>A</a> <a title="a>b" href="https://example.com/b?utm_source=news">B</a>')
        ->and($tags->html('<a download title="see href=https://example.com/x" HREF = "https://example.com/y">Y</a>'))
        ->toBe('<a download title="see href=https://example.com/x" HREF = "https://example.com/y?utm_source=news">Y</a>');
});
