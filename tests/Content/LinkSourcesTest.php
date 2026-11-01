<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\LinkSource;
use Mozex\Campaigns\Content\LinkSources;

it('reads every link, Outlook\'s VML buttons included, and nothing else', function (): void {
    $html = <<<'HTML'
        <html><head><link href="https://cdn.test/a.css" rel="stylesheet"><title><a href="https://old.test/title">x</a></title></head>
        <body>
        <!--[if mso]><v:roundrect href="https://shop.test/button" arcsize="10%"><center>Buy</center></v:roundrect><![endif]-->
        <!--[if !mso]><!--><a href="https://shop.test/button">Buy</a><!--<![endif]-->
        <!-- <a href="https://old.test/commented">x</a> -->
        <map><area href="https://shop.test/area" alt=""></map>
        <A HREF = 'https://shop.test/?a=1&amp;b=2' class="x">Go</A>
        <a href=https://shop.test/bare>Bare</a>
        <a name="anchor">No link</a>
        <a href="mailto:hi@shop.test">Mail</a>
        <a href="{{unsubscribe_url}}">Leave</a>
        <script>var a = '<a href="https://old.test/script">';</script>
        <svg><image href="https://cdn.test/svg.png" /></svg>
        </body></html>
        HTML;

    expect(array_map(fn (LinkSource $link): array => [$link->element, $link->url], LinkSources::in($html)))->toBe([
        ['v:roundrect', 'https://shop.test/button'],
        ['a', 'https://shop.test/button'],
        ['area', 'https://shop.test/area'],
        ['a', 'https://shop.test/?a=1&b=2'],
        ['a', 'https://shop.test/bare'],
        ['a', 'mailto:hi@shop.test'],
        ['a', '{{unsubscribe_url}}'],
    ]);
});

it('gives each href where it is written, and each link its visible text', function (): void {
    $html = '<p>Hi</p><a title="a>b" href=" https://shop.test/x ">Our <b>shop</b> &amp; more</a>'
        .'<a href="https://shop.test/y"><img src="https://cdn.test/i.png" alt=""></a>'
        .'<a href="https://shop.test/z">https://shop.test/z</a><a href="https://shop.test/w"> www.shop.test </a>';

    $links = LinkSources::in($html);

    expect(substr($html, $links[0]->offset, $links[0]->length))->toBe(' https://shop.test/x ')
        ->and($links[0]->url)->toBe('https://shop.test/x')
        ->and($links[0]->text)->toBe('Our shop & more')
        ->and($links[0]->showsAddress())->toBeFalse()
        ->and($links[1]->text)->toBe('')
        ->and($links[1]->showsAddress())->toBeFalse()
        ->and($links[2]->showsAddress())->toBeTrue()
        ->and($links[3]->showsAddress())->toBeTrue();
});

it('finds where a link is marked to stay untracked', function (): void {
    $html = '<a href="https://shop.test/a" data-campaigns-no-track>A</a><a data-campaigns-no-track="" href="https://shop.test/b">B</a><a href="https://shop.test/c">C</a>';

    $links = LinkSources::in($html);

    expect(substr($html, ...[$links[0]->noTrack[0], $links[0]->noTrack[1] - $links[0]->noTrack[0]]))->toBe('data-campaigns-no-track')
        ->and(substr($html, ...[$links[1]->noTrack[0], $links[1]->noTrack[1] - $links[1]->noTrack[0]]))->toBe('data-campaigns-no-track=""')
        ->and($links[2]->noTrack)->toBeNull();
});

it('gives offsets in the email for links inside a conditional comment', function (): void {
    $html = '<p>x</p><!--[if mso]><v:rect href="https://shop.test/vml" data-campaigns-no-track></v:rect><![endif]--><a href="https://shop.test/a">A</a>';

    [$vml, $link] = LinkSources::in($html);

    expect(substr($html, $vml->offset, $vml->length))->toBe('https://shop.test/vml')
        ->and(substr($html, $vml->noTrack[0], $vml->noTrack[1] - $vml->noTrack[0]))->toBe('data-campaigns-no-track')
        ->and(substr($html, $link->offset, $link->length))->toBe('https://shop.test/a');
});

it('reads thousands of unclosed links in one pass', function (): void {
    $html = str_repeat('<a href="https://shop.test/x">text ', 20_000);
    $started = microtime(true);

    $links = LinkSources::in($html);

    expect($links)->toHaveCount(20_000)
        ->and($links[0]->text)->toBe('text')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});
