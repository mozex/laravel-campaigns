<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\MessageComposer;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;

/**
 * A sending campaign whose frozen revision holds English and French.
 */
function frozenCampaign(array $attributes = []): Campaign
{
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending, 'reply_to' => 'help@example.com', ...$attributes]);

    $revision = Revision::factory()->of($campaign)
        ->withContent('en', ['subject' => 'Hi {{first_name|there}}', 'html' => '<html><body><p>Hi {{first_name|there}}, {{email}}</p><a href="{{web_version_url}}">Web</a>{{footer}}</body></html>', 'text' => 'Hi {{first_name|there}} {{footer}}', 'text_generated' => true])
        ->withContent('fr', ['subject' => 'Salut {{first_name}}', 'html' => '<p>Salut</p>{{footer}}', 'text' => 'Salut {{unsubscribe_url}}'])
        ->create();

    $campaign->update(['frozen_revision_id' => $revision->id]);

    return $campaign;
}

it('builds a campaign message from the frozen revision in the recipient\'s language, filled with their details', function (): void {
    $campaign = frozenCampaign();
    $subscriber = Subscriber::factory()->create(['first_name' => 'Zoë', 'email' => 'zoe@example.org']);
    $token = Token::generate();

    // The campaign's current revision has moved on; the frozen one goes out.
    Revision::factory()->of($campaign)->withContent('en', ['subject' => 'A later draft'])->create();

    $english = CampaignMessage::factory()->for($campaign)->for($subscriber)->create(['locale' => 'en_GB']);
    $french = CampaignMessage::factory()->for($campaign)->for(Subscriber::factory()->state(['country' => 'FR']))->create(['locale' => 'fr_CA']);

    $message = app(MessageComposer::class)->outgoing($english, $token, Token::generate());
    $toFrance = app(MessageComposer::class)->outgoing($french, Token::generate(), Token::generate());

    expect($message->uuid)->toBe($english->uuid)
        ->and($message->to)->toBe('zoe@example.org')
        ->and($message->subject)->toBe('Hi Zoë')
        ->and($message->html)->toContain('Hi Zoë, zoe@example.org')
        ->and($message->html)->toContain('href="'.Links::webVersion($token).'"')
        ->and($message->html)->toContain(Links::unsubscribe($token))
        ->and($message->text)->toContain('Hi Zoë')
        ->and($message->unsubscribeUrl)->toBe(Links::unsubscribe($token))
        ->and($message->fromEmail)->toBe('team@news.example.com')
        ->and($message->fromName)->toBe('Example Team')
        ->and($message->replyTo)->toBe('help@example.com')
        ->and($message->tag)->toBe($campaign->tag)
        ->and($message->trackOpens)->toBeTrue()
        ->and($message->trackClicks)->toBeTrue()
        ->and($message->isTest)->toBeFalse()
        ->and($message->metadata())->toBe(['campaign-message' => $english->uuid])
        ->and($toFrance->subject)->toStartWith('Salut')
        ->and($toFrance->trackOpens)->toBeFalse()
        ->and($toFrance->trackClicks)->toBeTrue();
});

it('follows the campaign\'s tracking switches and refuses an email it can\'t build', function (): void {
    $campaign = frozenCampaign(['track_opens' => false, 'track_clicks' => false]);
    $message = CampaignMessage::factory()->for($campaign)->create();
    $forgotten = CampaignMessage::factory()->for($campaign)->create(['email' => null]);
    $unfrozen = CampaignMessage::factory()->create();

    $outgoing = app(MessageComposer::class)->outgoing($message, Token::generate(), Token::generate());

    expect($outgoing->trackOpens)->toBeFalse()
        ->and($outgoing->trackClicks)->toBeFalse()
        ->and(fn () => app(MessageComposer::class)->outgoing($forgotten, Token::generate(), Token::generate()))->toThrow(CampaignsException::class, 'forgotten')
        ->and(fn () => app(MessageComposer::class)->outgoing($unfrozen, Token::generate(), Token::generate()))->toThrow(CampaignsException::class, 'no email');
});

it('builds a test email from its own revision, from the app\'s sender for a template', function (): void {
    sendFromExample();
    config()->set('campaigns.mail.reply_to', 'replies@example.com');

    $template = Template::factory()->create();
    $revision = Revision::factory()->of($template)->withContent('en', ['subject' => 'Hi {{first_name|there}}'])->create();
    Subscriber::factory()->create(['email' => 'owner@example.com', 'first_name' => 'Owner']);

    $test = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'owner@example.com', 'token_hash' => null]);
    $stranger = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'nobody@example.com', 'token_hash' => null]);

    $message = app(MessageComposer::class)->outgoing($test, Token::generate(), Token::generate());

    expect($message->subject)->toBe('Hi Owner')
        ->and(app(MessageComposer::class)->outgoing($stranger, Token::generate(), Token::generate())->subject)->toBe('Hi there')
        ->and($message->fromEmail)->toBe('news@news.example.com')
        ->and($message->fromName)->toBe('Example News')
        ->and($message->replyTo)->toBe('replies@example.com')
        ->and($message->tag)->toBe(TestSend::TAG)
        ->and($message->isTest)->toBeTrue()
        ->and($message->metadata())->toBe(['campaign-test' => $test->uuid]);
});

/**
 * A sending campaign frozen with the given HTML, its link rows written as its
 * start writes them.
 *
 * @param  array<string, mixed>  $attributes
 */
function trackedCampaign(string $html, array $attributes = []): Campaign
{
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending, ...$attributes]);
    $revision = Revision::factory()->of($campaign)->withContent('en', ['html' => $html, 'preheader' => 'Spring is here', 'text' => 'Shop: https://shop.test/offer {{footer}}'])->create();
    $campaign->update(['frozen_revision_id' => $revision->id]);
    CampaignLink::writeFor($revision);

    return $campaign;
}

/**
 * The tracked link a row's address is wrapped in.
 */
function trackedLink(Campaign $campaign, string $url, string $trackingToken): string
{
    $key = CampaignLink::query()->where('revision_id', $campaign->frozen_revision_id)->where('url', $url)->value('key');

    return htmlspecialchars(Links::click((string) $key, $trackingToken), ENT_QUOTES | ENT_HTML5);
}

describe('tracking', function (): void {
    it('wraps each link to an address of its revision, and nothing else, Outlook\'s buttons included', function (): void {
        $html = implode('', [
            '<html><head><title>x</title></head><body>',
            '<a href="https://shop.test/offer?a=1&amp;b=2">Offer</a>',
            '<a href="https://shop.test/offer?a=1&amp;b=2">The same offer</a>',
            '<!--[if mso]><v:roundrect href="https://shop.test/button"><center>Buy</center></v:roundrect><![endif]-->',
            '<a href="https://shop.test/?ref={{email}}">Personal</a>',
            '<a href="mailto:hi@shop.test">Mail us</a>',
            '<a href="{{web_version_url}}">Web</a>',
            '<a href="'.Links::webVersion(str_repeat('a', 48)).'">An own page written out</a>',
            '<a href="https://shop.test/kept" data-campaigns-no-track>Kept</a>',
            '<a href="https://shop.test/shown">https://shop.test/shown</a>',
            '<a href="#top">Top</a>',
            '{{footer}}</body></html>',
        ]);
        $campaign = trackedCampaign($html);
        $message = CampaignMessage::factory()->for($campaign)->create(['email' => 'zoe@example.org']);
        $tracking = Token::generate();

        $outgoing = app(MessageComposer::class)->outgoing($message, Token::generate(), $tracking);

        expect(CampaignLink::query()->where('revision_id', $campaign->frozen_revision_id)->pluck('url')->sort()->values()->all())
            ->toBe(['https://shop.test/button', 'https://shop.test/offer?a=1&b=2'])
            ->and(substr_count($outgoing->html, 'href="'.trackedLink($campaign, 'https://shop.test/offer?a=1&b=2', $tracking).'"'))->toBe(2)
            ->and($outgoing->html)->toContain('<v:roundrect href="'.trackedLink($campaign, 'https://shop.test/button', $tracking).'">')
            ->toContain('href="https://shop.test/?ref=zoe@example.org"')
            ->toContain('href="mailto:hi@shop.test"')
            ->toContain('href="'.Links::webVersion(str_repeat('a', 48)).'"')
            ->toContain('<a href="https://shop.test/kept" >Kept</a>')
            ->toContain('href="https://shop.test/shown"')
            ->toContain('href="#top"')
            ->not->toContain('data-campaigns-no-track')
            ->and(substr_count($outgoing->html, '/campaigns/l/'))->toBe(3)
            // The footer's own links lead to the package's pages, unwrapped.
            ->and($outgoing->html)->toContain('/campaigns/u/')
            ->and($outgoing->text)->toContain('Shop: https://shop.test/offer')
            ->and($outgoing->text)->not->toContain('/campaigns/l/')
            ->not->toContain('/campaigns/o/');
    });

    it('puts the open pixel right after the hidden preheader, and none for a recipient whose opens need consent', function (): void {
        $campaign = trackedCampaign('<html><head><style>p { color: red; }</style></head><body><p>Hi</p><a href="https://shop.test/offer">Offer</a>{{footer}}</body></html>');
        $here = CampaignMessage::factory()->for($campaign)->for(Subscriber::factory()->state(['country' => 'NL']))->create();
        $france = CampaignMessage::factory()->for($campaign)->for(Subscriber::factory()->state(['country' => 'FR']))->create();
        $tracking = Token::generate();

        $outgoing = app(MessageComposer::class)->outgoing($here, Token::generate(), $tracking);
        $toFrance = app(MessageComposer::class)->outgoing($france, Token::generate(), $other = Token::generate());
        $pixel = '<img src="'.Links::open($tracking).'" width="1" height="1" alt="" style="display:block;border:0">';

        expect($outgoing->html)->toContain('Spring is here</div>'.$pixel.'<p>Hi</p>')
            ->and(substr_count($outgoing->html, '/campaigns/o/'))->toBe(1)
            ->and($toFrance->trackOpens)->toBeFalse()
            ->and($toFrance->trackClicks)->toBeTrue()
            ->and($toFrance->html)->not->toContain('/campaigns/o/')
            ->toContain('href="'.trackedLink($campaign, 'https://shop.test/offer', $other).'"');
    });

    it('wraps nothing and adds no pixel when its campaign tracks neither, taking out the no-track marks all the same', function (): void {
        $campaign = trackedCampaign('<a href="https://shop.test/offer">Offer</a><a href="https://shop.test/kept" data-campaigns-no-track>Kept</a>{{footer}}', ['track_opens' => false, 'track_clicks' => false]);
        $message = CampaignMessage::factory()->for($campaign)->create();

        $outgoing = app(MessageComposer::class)->outgoing($message, Token::generate(), Token::generate());

        expect($outgoing->html)->toContain('href="https://shop.test/offer"')
            ->not->toContain('/campaigns/l/')
            ->not->toContain('/campaigns/o/')
            ->not->toContain('data-campaigns-no-track');
    });

    it('shows the web version untracked', function (): void {
        $campaign = trackedCampaign('<a href="https://shop.test/offer" data-campaigns-no-track>Offer</a><a href="https://shop.test/other">Other</a>{{footer}}');
        $message = CampaignMessage::factory()->for($campaign)->create();

        $rendered = app(MessageComposer::class)->rendered($message, Token::generate());

        expect($rendered->html)->toContain('href="https://shop.test/other"')
            ->not->toContain('/campaigns/l/')
            ->not->toContain('/campaigns/o/')
            ->not->toContain('data-campaigns-no-track');
    });

    it('tracks a test email, its revision\'s rows made the first time one is composed', function (): void {
        sendFromExample();
        $template = Template::factory()->create();
        $revision = Revision::factory()->of($template)->withContent('en', ['html' => '<a href="https://shop.test/offer">Offer</a>{{footer}}'])->create();
        $first = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'owner@example.com', 'token_hash' => null]);
        $second = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'owner@example.com', 'token_hash' => null]);

        $one = app(MessageComposer::class)->outgoing($first, Token::generate(), Token::generate());
        $two = app(MessageComposer::class)->outgoing($second, Token::generate(), Token::generate());
        $link = CampaignLink::query()->where('revision_id', $revision->id)->sole();

        expect($link->url)->toBe('https://shop.test/offer')
            ->and($one->html)->toContain('/campaigns/l/'.$link->key.'/')
            ->and($two->html)->toContain('/campaigns/l/'.$link->key.'/')
            ->and($one->trackOpens)->toBeTrue()
            ->and($one->html)->toContain('/campaigns/o/');
    });

    it('wraps a link whose address holds a costly character where it is written', function (string $character): void {
        $url = 'https://shop.test/?q='.$character;
        $campaign = trackedCampaign('<p>Before</p><a title="'.e($character).'" href="'.e($url).'">Go</a><p>After</p>{{footer}}');
        $message = CampaignMessage::factory()->for($campaign)->create();
        $tracking = Token::generate();

        $outgoing = app(MessageComposer::class)->outgoing($message, Token::generate(), $tracking);

        expect(CampaignLink::query()->where('revision_id', $campaign->frozen_revision_id)->value('url'))->toBe($url)
            ->and($outgoing->html)->toContain('<a title="'.e($character).'" href="'.trackedLink($campaign, $url, $tracking).'">Go</a><p>After</p>');
    })->with('costly characters');
});
