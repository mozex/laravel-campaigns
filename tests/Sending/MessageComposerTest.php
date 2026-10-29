<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
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

    $message = app(MessageComposer::class)->outgoing($english, $token);
    $toFrance = app(MessageComposer::class)->outgoing($french, Token::generate());

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

    $outgoing = app(MessageComposer::class)->outgoing($message, Token::generate());

    expect($outgoing->trackOpens)->toBeFalse()
        ->and($outgoing->trackClicks)->toBeFalse()
        ->and(fn () => app(MessageComposer::class)->outgoing($forgotten, Token::generate()))->toThrow(CampaignsException::class, 'forgotten')
        ->and(fn () => app(MessageComposer::class)->outgoing($unfrozen, Token::generate()))->toThrow(CampaignsException::class, 'no email');
});

it('builds a test email from its own revision, from the app\'s sender for a template', function (): void {
    sendFromExample();
    config()->set('campaigns.mail.reply_to', 'replies@example.com');

    $template = Template::factory()->create();
    $revision = Revision::factory()->of($template)->withContent('en', ['subject' => 'Hi {{first_name|there}}'])->create();
    Subscriber::factory()->create(['email' => 'owner@example.com', 'first_name' => 'Owner']);

    $test = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'owner@example.com', 'token_hash' => null]);
    $stranger = TestSend::factory()->create(['campaign_id' => null, 'revision_id' => $revision->id, 'email' => 'nobody@example.com', 'token_hash' => null]);

    $message = app(MessageComposer::class)->outgoing($test, Token::generate());

    expect($message->subject)->toBe('Hi Owner')
        ->and(app(MessageComposer::class)->outgoing($stranger, Token::generate())->subject)->toBe('Hi there')
        ->and($message->fromEmail)->toBe('news@news.example.com')
        ->and($message->fromName)->toBe('Example News')
        ->and($message->replyTo)->toBe('replies@example.com')
        ->and($message->tag)->toBe(TestSend::TAG)
        ->and($message->isTest)->toBeTrue()
        ->and($message->metadata())->toBe(['campaign-test' => $test->uuid]);
});
