<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Html;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Content\PlainText;
use Mozex\Campaigns\Content\Renderer;
use Mozex\Campaigns\Templates\StarterTemplates;

/**
 * @param  list<EmailContent>  $contents
 */
function contentProblems(array $contents): string
{
    try {
        app(ContentRules::class)->prepare($contents);
    } catch (CampaignsException $exception) {
        return $exception->getMessage();
    }

    return '';
}

function validContent(string $locale = 'en', string $html = '<p>Hi {{first_name|there}}</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>', ?string $text = null, string $subject = 'News for {{first_name|you}}', ?string $preheader = null): EmailContent
{
    return new EmailContent(locale: $locale, subject: $subject, html: $html, text: $text, preheader: $preheader);
}

it('prepares a valid email, generating its plain text when none was given', function (): void {
    [$english, $persian] = app(ContentRules::class)->prepare([
        validContent(subject: '  Spaced subject  ', preheader: '   '),
        validContent('fa', text: "Salam\n{{footer}}"),
    ]);

    expect($english)
        ->subject->toBe('Spaced subject')
        ->preheader->toBeNull()
        ->text->toBe("Hi {{first_name|there}}\n\nUnsubscribe ({{unsubscribe_url}})")
        ->textGenerated->toBeTrue()
        ->and($persian)
        ->text->toBe("Salam\n{{footer}}")
        ->textGenerated->toBeFalse();
});

it('accepts the footer in place of the unsubscribe address', function (): void {
    expect(contentProblems([validContent(html: '<p>Hello</p>{{ footer }}', text: "Hello\n{{footer}}")]))->toBe('');
});

it('refuses an email without an unsubscribe link in the HTML or in the plain text, each on its own', function (): void {
    expect(contentProblems([validContent(html: '<p>No link</p>', text: 'Unsubscribe: {{unsubscribe_url}}')]))
        ->toContain('en: the HTML has no unsubscribe link')
        ->not->toContain('plain text has no unsubscribe link')
        ->and(contentProblems([validContent(text: 'No link here')]))
        ->toContain('en: the plain text has no unsubscribe link')
        ->not->toContain('the HTML has no unsubscribe link')
        // A link only an image carries never reaches the generated text.
        ->and(contentProblems([validContent(html: '<img src="https://cdn.test/{{unsubscribe_url}}.png" alt="">')]))
        ->toContain('en: the plain text has no unsubscribe link');
});

it('refuses what breaks the rules, every problem at once', function (array $contents, array $expected): void {
    $problems = contentProblems($contents);

    foreach ($expected as $problem) {
        expect(str_contains($problems, $problem))->toBeTrue("Missing \"{$problem}\" in: {$problems}");
    }
})->with([
    'no content' => [[], ['There is no content.']],
    'no default language' => [[validContent('fa')], ['There is no en version']],
    'a language twice' => [[validContent(), validContent()], ['en: given twice.']],
    'too many languages' => [[validContent(), ...array_map(fn (int $number): EmailContent => validContent('x'.chr(96 + $number)), range(1, 20))], ['There are 21 languages; an email holds at most 20.']],
    'not a locale' => [[validContent(), validContent('../../etc')], ['"../../etc" is not a locale']],
    'an empty subject' => [[validContent(subject: '  ')], ['en: the subject is empty.']],
    'a subject on two lines' => [[validContent(subject: "One\nTwo")], ['en: the subject must fit on one line.']],
    'a long subject and preheader' => [[validContent(subject: str_repeat('s', 256), preheader: str_repeat('p', 256))], [
        'en: the subject is longer than 255 characters.',
        'en: the preheader is longer than 255 characters.',
    ]],
    'the footer in the subject' => [[validContent(subject: 'Hi {{footer}}', preheader: '{{unsubscribe_url}}')], [
        'en: the subject may hold only {{first_name}}, {{name}} and {{email}}, not {{footer}}.',
        'en: the preheader may hold only {{first_name}}, {{name}} and {{email}}, not {{unsubscribe_url}}.',
    ]],
    'a language twice, written differently' => [[validContent(), validContent('EN'), validContent('pt_BR'), validContent('pt-br')], [
        'EN: given twice.',
        'pt-br: given twice.',
    ]],
    'unknown placeholders' => [[validContent(html: '<p>{{frist_name}} {{ Coupon }} {{first-name}} <a href="{{web-version-url}}">v</a> {{ $name }}</p>{{footer}}', text: '{{frist_name}} {{#if x}} {{footer}}', subject: 'Hi {{first name}}')], [
        'en: the HTML holds {{frist_name}}, {{coupon}}, {{first-name}}, {{web-version-url}}, {{ $name }}, which are not placeholders.',
        'en: the plain text holds {{frist_name}}, {{#if x}}, which are not placeholders.',
        'en: the subject holds {{first name}}, which is not a placeholder.',
    ]],
    'triple braces' => [[validContent(html: '<p>Hi {{{first_name}}}</p>{{footer}}')], ['en: the HTML holds {{{first_name}}}, which is not a placeholder.']],
    'braces a tool percent-encoded' => [[validContent(html: '<a href="%7B%7Bweb_version_url%7D%7D">View</a>{{footer}}')], ['en: the HTML holds %7B%7Bweb_version_url%7D%7D, a placeholder whose braces a tool percent-encoded; write it as {{web_version_url}}.']],
    'a plain text over 90 KB' => [[validContent(text: str_repeat('x', 91 * 1024).'{{footer}}')], ['en: the plain text is over 90 KB.']],
    'an empty HTML' => [[validContent(html: ' ')], ['en: the HTML is empty.']],
    'text that is not UTF-8' => [[validContent(subject: "Caf\xE9")], ["en: the subject isn't UTF-8 text."]],
    'images not on https' => [[validContent(html: implode('', [
        '<img src="http://cdn.test/a.png">',
        '<img src="images/b.png">',
        '<img src="https://cdn.test/c.png" srcset="https://cdn.test/c2.png 2x, //cdn.test/c3.png 3x">',
        '<table background="cdn.test/d.png"><tr><td style="background-image: url(\'data:image/png;base64,AAAA\')">x</td></tr></table>',
        '<img alt="none">',
        '{{footer}}',
    ]))], [
        'en: the image "http://cdn.test/a.png" is not on https.',
        'en: the image "images/b.png" is not on https.',
        'en: the image "//cdn.test/c3.png" is not on https.',
        'en: the image "cdn.test/d.png" is not on https.',
        'en: the image "data:image/png;base64,AAAA" is not on https.',
        'en: the image with no address is not on https.',
    ]],
]);

it('reads CSS images only from CSS, decoded', function (): void {
    expect(contentProblems([validContent(html: implode('', [
        '<td style="background-image: url(&quot;https://cdn.test/bg.png&quot;)">Run curl(1) on it.</td>',
        '<style>.hero { background: url( \'https://cdn.test/hero.png\' ); }</style>',
        '<svg><rect fill="url(#gradient)"/></svg>',
        '<style>v\:* { behavior: url(#default#VML); }</style>',
        '<img src="https://res.cloudinary.test/w_400,h_300/a.jpg" srcset="https://res.cloudinary.test/w_400,h_300/a.jpg 1x,https://res.cloudinary.test/w_800,h_600/a.jpg 2x" alt="">',
        '{{footer}}',
    ]))]))->toBe('')
        ->and(contentProblems([validContent(html: '<style>.hero { background: url(http://cdn.test/hero.png) }</style>{{footer}}')]))
        ->toContain('en: the image "http://cdn.test/hero.png" is not on https.');
});

it('refuses an image on http in every form it is written', function (): void {
    $addresses = [
        'http://cdn.test/vml.png', 'http://cdn.test/fill.png', 'http://cdn.test/td.png', 'http://cdn.test/outlook.png',
        'http://cdn.test/hero@2x.png', 'http://cdn.test/button.png', 'http://cdn.test/poster.png', 'http://cdn.test/low.png',
        'http://cdn.test/svg.png', 'http://cdn.test/fonts.css', 'http://cdn.test/set.png', 'http://cdn.test/open.png',
    ];

    expect(contentProblems([validContent(html: implode('', [
        '{{footer}}',
        '<!--[if mso]><v:image src="http://cdn.test/vml.png" /><v:fill src="http://cdn.test/fill.png" /><td background="http://cdn.test/td.png"><img src="http://cdn.test/outlook.png" alt=""><![endif]-->',
        '<picture><source srcset="http://cdn.test/hero@2x.png 2x"><img src="https://cdn.test/hero.png" alt=""></picture>',
        '<input type="image" src="http://cdn.test/button.png" alt=""><video poster="http://cdn.test/poster.png"></video>',
        '<img lowsrc="http://cdn.test/low.png" src="https://cdn.test/a.png" alt=""><svg><image href="http://cdn.test/svg.png" /></svg>',
        '<style>@import "http://cdn.test/fonts.css"; .s { background: image-set("http://cdn.test/set.png" 1x); } .e { background: url(http://cdn.test/open.png',
    ]))]))->toContain(...array_map(fn (string $address): string => "en: the image \"{$address}\" is not on https.", $addresses));
});

it('refuses HTML nested deeper than its rules can read', function (): void {
    $deep = str_repeat('<div>', 300).'<img src="https://cdn.test/deep.png" alt="">'.str_repeat('</div>', 300).'{{footer}}';

    expect(contentProblems([validContent(html: $deep)]))->toContain('en: the HTML nests elements more than 200 deep');
});

it('reads an email nested to the limit whole once its footer is filled in', function (): void {
    // html and body are the first two levels, so the paragraph inside the
    // innermost div is at the limit.
    $atLimit = str_repeat('<div>', Html::MAX_DEPTH - 3).'{{footer}}<p>After the footer</p>'.str_repeat('</div>', Html::MAX_DEPTH - 3);

    [$accepted] = app(ContentRules::class)->prepare([validContent(html: $atLimit)]);

    // The footer's own nesting stays inside what libxml reads.
    expect(PlainText::fromHtml(app(Renderer::class)->render($accepted, Personalization::sample('en'))->html))
        ->toContain('After the footer', 'Unsubscribe or change your email preferences');
});

it('keeps the app\'s default language whatever language a request switches to', function (): void {
    app()->setLocale('fa');

    expect(contentProblems([validContent()]))->toBe('')
        ->and(contentProblems([validContent('EN')]))->toBe('')
        ->and(contentProblems([validContent('fa')]))->toContain('There is no en version');
});

it('measures the 90 KB limit on the email as a recipient gets it', function (): void {
    // Under the limit as written; over it once each placeholder becomes an
    // address, since a recipient's links are longer than their placeholders.
    $html = '<p>'.str_repeat('x', 84 * 1024).'</p>'.str_repeat('<a href="{{unsubscribe_url}}">u</a>', 150);

    expect(strlen($html))->toBeLessThan(90 * 1024)
        ->and(contentProblems([validContent(html: $html)]))->toContain('en: the HTML is 9')
        ->and(contentProblems([validContent(html: '<p>'.str_repeat('x', 80 * 1024).'</p>{{footer}}')]))->toBe('');
});

it('counts the tracked links and the open pixel in the 90 KB limit', function (): void {
    // Under the limit filled in, over it once each of its many links is
    // wrapped in a tracked one; the same links marked to stay untracked pass.
    $links = fn (string $mark): string => str_repeat('<a href="https://shop.test/a"'.$mark.'>a</a>', 1100);

    expect(strlen($links('')))->toBeLessThan(80 * 1024)
        ->and(contentProblems([validContent(html: $links('').'{{footer}}')]))->toContain('KB once filled in, with its tracked links and open pixel, over the 90 KB limit')
        ->and(contentProblems([validContent(html: $links(' data-campaigns-no-track').'{{footer}}')]))->toBe('');
});

it('refuses HTML over the limit as written without rendering it', function (): void {
    $this->mock(Renderer::class)->shouldNotReceive('render');

    expect(contentProblems([validContent(html: '<p>'.str_repeat('x', 1024 * 1024).'</p>{{footer}}', preheader: 'Pre')]))
        ->toContain('en: the HTML is 1024 KB');
});

it('lets every starter template through, whatever the default language', function (string $locale): void {
    config()->set('campaigns.locale', $locale);

    foreach (StarterTemplates::all() as $starter) {
        expect(contentProblems($starter->contents))->toBe('');
    }
})->with(['en', 'fa']);
