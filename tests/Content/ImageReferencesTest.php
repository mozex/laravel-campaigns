<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\ImageReferences;

it('finds relative image paths and swaps them in place, leaving everything else byte for byte', function (): void {
    $html = <<<'HTML'
        <html><head><style>.hero { background: url('images/bg.png'); } .icon { background: url(https://cdn.example.com/i.png); }</style></head>
        <body>
        <a href="{{web_version_url}}?a=1&amp;b={{email}}">View</a>
        <img src="images/a.png" alt="A">
        <img src='./images/a.png' alt="B" data-src="images/a.png">
        <img src=images/a.png alt=C>
        <img srcset="images/a.png 1x, https://cdn.example.com/b.png 2x" src="https://cdn.example.com/b.png" alt="D">
        <table><tr><td background="images/bg.png" style="background-image: url(&quot;images/bg.png&quot;)">x</td></tr></table>
        <img src="#top" alt=""><img src="data:image/gif;base64,R0lGOD" alt=""><img src="//cdn.example.com/c.png" alt="">
        </body></html>
        HTML;

    $urls = [
        'images/a.png' => 'https://assets.example.com/a.png',
        './images/a.png' => 'https://assets.example.com/a.png',
        'images/bg.png' => 'https://assets.example.com/bg.png',
    ];

    expect(ImageReferences::relative($html))->toEqualCanonicalizing(['images/a.png', './images/a.png', 'images/bg.png'])
        ->and(ImageReferences::rewrite($html, fn (string $reference): ?string => $urls[$reference] ?? null))->toBe(<<<'HTML'
            <html><head><style>.hero { background: url('https://assets.example.com/bg.png'); } .icon { background: url(https://cdn.example.com/i.png); }</style></head>
            <body>
            <a href="{{web_version_url}}?a=1&amp;b={{email}}">View</a>
            <img src="https://assets.example.com/a.png" alt="A">
            <img src='https://assets.example.com/a.png' alt="B" data-src="images/a.png">
            <img src=https://assets.example.com/a.png alt=C>
            <img srcset="https://assets.example.com/a.png 1x, https://cdn.example.com/b.png 2x" src="https://cdn.example.com/b.png" alt="D">
            <table><tr><td background="https://assets.example.com/bg.png" style="background-image: url(&quot;https://assets.example.com/bg.png&quot;)">x</td></tr></table>
            <img src="#top" alt=""><img src="data:image/gif;base64,R0lGOD" alt=""><img src="//cdn.example.com/c.png" alt="">
            </body></html>
            HTML);
});
