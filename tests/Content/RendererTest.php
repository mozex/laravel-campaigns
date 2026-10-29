<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Content\RenderedEmail;
use Mozex\Campaigns\Content\Renderer;
use Mozex\Campaigns\Support\Links;

function renderEmail(EmailContent $content, ?Personalization $person = null): RenderedEmail
{
    return app(Renderer::class)->render($content, $person ?? Personalization::forToken(
        token: 'the-token',
        email: 'ann@example.com',
        firstName: 'Ann',
        name: 'Ann Example',
        webVersionUrl: 'https://app.test/campaigns/v/the-token',
    ));
}

describe('placeholders', function (): void {
    it('fills every placeholder, escaped in the HTML and as written in the text', function (): void {
        config()->set('campaigns.postal_address', '1 Main St & Co, Springfield');
        config()->set('campaigns.privacy_url', 'https://app.test/privacy?a=1&b=2');

        $placeholders = '{{first_name}}|{{ name }}|{{email}}|{{unsubscribe_url}}|{{preferences_url}}|{{web_version_url}}|{{postal_address}}|{{privacy_url}}';

        $email = renderEmail(
            new EmailContent(locale: 'en', subject: 'Hi', html: "<p>{$placeholders}</p>", text: $placeholders),
            Personalization::forToken('the-token', 'ann@example.com', firstName: 'Ann <b>', name: 'Ann & Co', webVersionUrl: 'https://app.test/v?x=1&y=2'),
        );

        $unsubscribe = Links::unsubscribe('the-token');

        expect($email->html)->toBe("<p>Ann &lt;b&gt;|Ann &amp; Co|ann@example.com|{$unsubscribe}|{$unsubscribe}|https://app.test/v?x=1&amp;y=2|1 Main St &amp; Co, Springfield|https://app.test/privacy?a=1&amp;b=2</p>")
            ->and($email->text)->toBe("Ann <b>|Ann & Co|ann@example.com|{$unsubscribe}|{$unsubscribe}|https://app.test/v?x=1&y=2|1 Main St & Co, Springfield|https://app.test/privacy?a=1&b=2")
            ->and($unsubscribe)->toBe('http://localhost/campaigns/u/the-token');
    });

    it('uses a fallback for a value that is missing or blank, and nothing without one', function (): void {
        $email = renderEmail(
            new EmailContent(locale: 'en', subject: 'Hi {{first_name|there}}', html: '<p>Hi {{first_name|Tom &amp; Jerry}}, {{name}}.</p>', text: 'Hi {{ first_name | friend }}{{postal_address}}'),
            Personalization::forToken('t', 'ann@example.com', firstName: '  '),
        );

        expect($email->subject)->toBe('Hi there')
            ->and($email->html)->toBe('<p>Hi Tom &amp; Jerry, .</p>')
            ->and($email->text)->toBe('Hi friend');
    });

    it('shows a value as the text it is, entities and all', function (): void {
        $email = renderEmail(
            new EmailContent(locale: 'en', subject: 'Hi', html: '<p>{{name}}</p>{{footer}}'),
            Personalization::forToken('t', 'ann@example.com', name: 'AT&amp;T'),
        );

        expect($email->html)->toStartWith('<p>AT&amp;amp;T</p>');
    });

    it('never reads a value it put in as a placeholder', function (): void {
        $email = renderEmail(
            new EmailContent(locale: 'en', subject: '{{first_name}}', html: '<p>{{first_name}} {{name}}</p>', text: '{{first_name}} {{name}}'),
            Personalization::forToken('t', 'ann@example.com', firstName: '{{footer}}', name: '{{unsubscribe_url}}'),
        );

        expect($email->html)->toBe('<p>{{footer}} {{unsubscribe_url}}</p>')
            ->and($email->text)->toBe('{{footer}} {{unsubscribe_url}}')
            ->and($email->subject)->toBe('{{footer}}');
    });

    it('keeps the subject raw and on one line, whatever a name holds', function (): void {
        $email = renderEmail(
            new EmailContent(locale: 'en', subject: 'Tom & Jerry, {{first_name}} <news>', html: '<p>{{unsubscribe_url}}</p>', preheader: 'For {{name}}'),
            Personalization::forToken('t', 'ann@example.com', firstName: "Ann\r\nBcc: someone@example.com", name: "A\nB"),
        );

        expect($email->subject)->toBe('Tom & Jerry, Ann Bcc: someone@example.com <news>')
            ->and($email->preheader)->toBe('For A B');
    });
});

describe('the preheader', function (): void {
    it('goes into a hidden block right after the body tag when the HTML does not place it', function (): void {
        $email = renderEmail(new EmailContent(
            locale: 'en',
            subject: 'Hi',
            html: '<html><body style="margin: 0"><p>Body</p></body></html>',
            preheader: 'A line for {{first_name}} & you',
        ));

        expect($email->html)->toStartWith('<html><body style="margin: 0"><div style="display: none;')
            ->and($email->html)->toContain('>A line for Ann &amp; you</div><p>Body</p>')
            ->and($email->preheader)->toBe('A line for Ann & you');
    });

    it('goes where the HTML places it, once, and nowhere without one', function (): void {
        $placed = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '<body><span>{{preheader}}</span><p>Body</p></body>', preheader: 'Pre'));
        $none = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '<body><p>Body</p></body>', preheader: ' '));
        $bodiless = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '<p>Body</p>', preheader: 'Pre'));

        expect($placed->html)->toBe('<body><span>Pre</span><p>Body</p></body>')
            ->and($none->html)->toBe('<body><p>Body</p></body>')
            ->and($none->preheader)->toBeNull()
            ->and($bodiless->html)->toStartWith('<div style="display: none;')
            ->and($bodiless->html)->toEndWith('>Pre</div><p>Body</p>');
    });

    it('goes where the parser starts the body when the HTML has no body tag, never before its doctype', function (string $html, string $before, string $after): void {
        $email = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: $html, preheader: 'Pre'));
        [$head, $tail] = explode('<div style="display: none;', $email->html, 2);

        expect($head)->toBe($before)
            ->and(Str::after($tail, '>Pre</div>'))->toBe($after);
    })->with([
        'a closed head' => ['<!DOCTYPE html><html lang="en"><head><style>p{}</style></head><table></table></html>', '<!DOCTYPE html><html lang="en"><head><style>p{}</style></head>', '<table></table></html>'],
        'a head never closed' => ["<!doctype html><html><head><meta charset=\"utf-8\"><title>Hi</title>\n<table></table>", "<!doctype html><html><head><meta charset=\"utf-8\"><title>Hi</title>\n", '<table></table>'],
        'no head' => ['<!DOCTYPE html><html><table></table></html>', '<!DOCTYPE html><html>', '<table></table></html>'],
        'a doctype alone' => ["\u{FEFF}<!-- built -->\n<!DOCTYPE html><table></table>", "\u{FEFF}<!-- built -->\n<!DOCTYPE html>", '<table></table>'],
        'a head\'s tags without a head' => ['<!DOCTYPE html><meta charset="utf-8"><style>td{}</style><table></table>', '<!DOCTYPE html><meta charset="utf-8"><style>td{}</style>', '<table></table>'],
        'an Outlook comment in a head never closed' => ['<html><head><!--[if mso]><xml><o:x/></xml><![endif]--><table></table>', '<html><head><!--[if mso]><xml><o:x/></xml><![endif]-->', '<table></table>'],
        'text first' => ['Hi {{first_name}},<p>Body</p>', '', 'Hi Ann,<p>Body</p>'],
    ]);
});

describe('the footer', function (): void {
    it('carries the unsubscribe and privacy links and the postal address, in HTML and in text', function (): void {
        config()->set('campaigns.name', 'Acme');
        config()->set('campaigns.postal_address', '1 Main St, Springfield');
        config()->set('campaigns.privacy_url', 'https://app.test/privacy');

        $email = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '<div>{{footer}}</div>', text: "Body\n\n{{footer}}"));

        expect($email->html)
            ->toContain('Acme sent this email to ann@example.com.')
            ->toContain('href="http://localhost/campaigns/u/the-token"')
            ->toContain('>Unsubscribe or change your email preferences</a>')
            ->toContain('href="https://app.test/privacy"')
            ->toContain('1 Main St, Springfield')
            ->and($email->text)->toBe(implode("\n", [
                'Body',
                '',
                'Acme sent this email to ann@example.com.',
                '',
                'Unsubscribe or change your email preferences: http://localhost/campaigns/u/the-token',
                'Privacy policy: https://app.test/privacy',
                '',
                '1 Main St, Springfield',
            ]));
    });

    it('leaves out the privacy link and postal address the app has none of', function (): void {
        config()->set('campaigns.privacy_url', '  ');

        $email = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '{{footer}}', text: '{{footer}}'));

        expect($email->html)->not->toContain('Privacy policy')
            ->and($email->text)->not->toContain('Privacy policy')
            ->and($email->text)->toEndWith('Unsubscribe or change your email preferences: http://localhost/campaigns/u/the-token');
    });

    it('speaks the email\'s language and leaves the app\'s as it was', function (): void {
        app('translator')->addLines([
            'pages.subscribed.heading' => 'Abonné',
            'mail.footer.sent_to' => ':app a envoyé cet e-mail à :email.',
        ], 'fr', 'campaigns');

        $french = renderEmail(new EmailContent(locale: 'fr', subject: 'Salut', html: '{{footer}}', text: '{{footer}}'));
        $canadian = renderEmail(new EmailContent(locale: 'fr_CA', subject: 'Salut', html: '{{footer}}', text: '{{footer}}'));
        $unknown = renderEmail(new EmailContent(locale: 'xx', subject: 'Hi', html: '{{footer}}', text: '{{footer}}'));

        expect($french->text)->toStartWith('Laravel a envoyé cet e-mail à ann@example.com.')
            // A regional version takes its language's strings before the app's.
            ->and($canadian->text)->toStartWith('Laravel a envoyé cet e-mail à ann@example.com.')
            ->and($unknown->text)->toStartWith('Laravel sent this email to ann@example.com.')
            ->and(app()->getLocale())->toBe('en');
    });
});

it('makes the plain text from the HTML when none was given', function (): void {
    $email = renderEmail(new EmailContent(locale: 'en', subject: 'Hi', html: '<p>Hi {{first_name}}</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>'));

    expect($email->text)->toBe("Hi Ann\n\nUnsubscribe (http://localhost/campaigns/u/the-token)");
});
