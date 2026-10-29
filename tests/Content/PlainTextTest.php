<?php

declare(strict_types=1);

use Mozex\Campaigns\Content\PlainText;

it('turns an email\'s HTML into readable text', function (): void {
    $html = <<<'HTML'
        <!DOCTYPE html>
        <html>
        <head><title>Title</title><style>p { color: red; }</style></head>
        <body>
            <div style="display:none; max-height: 0">Hidden preheader</div>
            <h1>Big   news</h1>
            <p>Hello&nbsp;{{first_name|there}},<br>line two &amp; more.</p>
            <table><tr><td>Left</td><td>Right</td></tr></table>
            <ul><li>One</li><li>Two</li></ul>
            <p><img src="https://cdn.test/logo.png" alt="Acme logo"><img src="https://cdn.test/spacer.gif" alt=""></p>
            <p><a href="https://example.com/offer?a=1&amp;b=2">See the offer</a> or <a href="https://example.com">https://example.com</a>.</p>
            <p><a href="mailto:help@example.com">help@example.com</a>, <a href="mailto:team@example.com">write to us</a>, <a href="#top">top</a></p>
            <hr>
            <p><a href="{{unsubscribe_url}}">Unsubscribe</a> &middot; {{footer}}</p>
            <script>alert(1)</script>
        </body>
        </html>
        HTML;

    expect(PlainText::fromHtml($html))->toBe(implode("\n", [
        'Big news',
        '',
        'Hello {{first_name|there}},',
        'line two & more.',
        '',
        'Left Right',
        '',
        '- One',
        '- Two',
        '',
        'Acme logo',
        '',
        'See the offer (https://example.com/offer?a=1&b=2) or https://example.com.',
        '',
        'help@example.com, write to us (team@example.com), top',
        '',
        '----',
        '',
        'Unsubscribe ({{unsubscribe_url}}) · {{footer}}',
    ]));
});

it('reads multibyte text as UTF-8', function (): void {
    expect(PlainText::fromHtml('<p>سلام دنیا — café</p>'))->toBe('سلام دنیا — café');
});
