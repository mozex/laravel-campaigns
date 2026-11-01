<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\DatabaseFailedException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;
use Symfony\Component\HttpFoundation\Response;

/** A desktop browser's user agent: a person. */
const DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

beforeEach(function (): void {
    Queue::fake();
});

/**
 * A sent message of a campaign frozen with one tracked link, and the raw
 * tracking token its pixel and links carry.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: CampaignMessage, 1: string, 2: CampaignLink}
 */
function trackedEmail(array $attributes = []): array
{
    $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending]);
    $revision = Revision::factory()->of($campaign)->withContent('en', ['html' => '<a href="https://shop.test/offer?a=1">Offer</a>{{footer}}'])->create();
    $campaign->update(['frozen_revision_id' => $revision->id]);
    CampaignLink::writeFor($revision);
    $token = Token::generate();

    $message = CampaignMessage::factory()->for($campaign)->for(Subscriber::factory())->create([
        'status' => MessageStatus::Sent,
        'sent_at' => now()->subHour(),
        ...$attributes,
        'tracking_token_hash' => Token::hash($token),
    ]);

    return [$message, $token, CampaignLink::query()->where('revision_id', $revision->id)->sole()];
}

/**
 * Applies every queued event's effects, as the worker would once their
 * delay is over.
 */
function applyHits(): void
{
    Queue::pushed(ProcessFeedbackJob::class)->each(fn (ProcessFeedbackJob $job) => app()->call([$job, 'handle']));
}

it('answers the pixel with a GIF nothing caches or keeps, and records an open of its email', function (): void {
    [$message, $token] = trackedEmail();

    $pixel = $this->get(Links::open($token), ['User-Agent' => DESKTOP_CHROME]);
    applyHits();
    $event = ProviderEvent::query()->sole();

    $pixel->assertOk()
        ->assertHeader('Content-Type', 'image/gif')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Set-Cookie');

    expect($pixel->getContent())->toBe(base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='))
        ->and(strlen((string) $pixel->getContent()))->toBe(43)
        ->and(collect(['no-store', 'no-cache', 'private'])->every(fn (string $directive): bool => $pixel->baseResponse->headers->hasCacheControlDirective($directive)))->toBeTrue()
        ->and($pixel->baseResponse->headers->getCacheControlDirective('max-age'))->toBe('0')
        ->and($event)
        ->driver->toBe('campaigns')
        ->type->toBe(FeedbackType::Opened)
        ->message_id->toBe($message->id)
        ->is_bot->toBeFalse()
        ->and(json_encode($event->payload))->not->toContain('Mozilla')->not->toContain('127.0.0.1')
        ->and($message->fresh()?->opened_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($message->subscriber?->fresh()?->last_opened_at?->getTimestamp())->toBe(now()->getTimestamp());
});

it('classifies a hit by its method and user agent alone', function (string $method, ?string $agent, ?string $bot, bool $machine): void {
    [, $token, $link] = trackedEmail();
    $server = $agent === null ? ['HTTP_USER_AGENT' => ''] : $this->transformHeadersToServerVars(['User-Agent' => $agent]);

    $this->call($method, Links::open($token), server: $server)->assertOk();
    $this->call($method, Links::click($link->key, $token), server: $server)->assertRedirect('https://shop.test/offer?a=1');

    expect(ProviderEvent::query()->orderBy('id')->get()->map(fn (ProviderEvent $event): array => [$event->type, $event->payload['bot'] ?? null, $event->is_bot])->all())->toBe([
        [FeedbackType::Opened, $bot, $machine],
        [FeedbackType::Clicked, $bot, $machine],
    ]);
})->with([
    'a desktop browser' => ['GET', DESKTOP_CHROME, null, false],
    'a phone named like a bot' => ['GET', 'Mozilla/5.0 (Linux; Android 10; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36', null, false],
    'Apple\'s privacy prefetch' => ['GET', 'Mozilla/5.0', 'apple', true],
    'Gmail\'s image proxy' => ['GET', 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)', 'gmail', false],
    'Yahoo\'s image proxy' => ['GET', 'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html', 'yahoo', false],
    'a HEAD request' => ['HEAD', DESKTOP_CHROME, 'generic', true],
    'no user agent' => ['GET', null, 'generic', true],
    'curl' => ['GET', 'curl/8.4.0', 'generic', true],
    'Python' => ['GET', 'python-requests/2.32.3', 'generic', true],
    'a crawler' => ['GET', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'generic', true],
    'a link expander' => ['GET', 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'generic', true],
    'a mail gateway' => ['GET', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Mimecast URL Protect', 'generic', true],
    'a headless browser' => ['GET', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'generic', true],
    'a chat app\'s link preview' => ['GET', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'generic', true],
    'a messenger\'s link preview' => ['GET', 'WhatsApp/2.23.20.0 A', 'generic', true],
    'the prefetchers\' Chrome 42' => ['GET', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.246 Mozilla/5.0', 'generic', true],
]);

it('counts what came as the email arrived as a machine\'s, an open within seconds and a click before its delivery', function (): void {
    [$justSent, $justSentToken, $link] = trackedEmail(['sent_at' => now()->subSeconds(3)]);
    [$read, $readToken] = trackedEmail(['sent_at' => now()->subMinutes(10)]);
    // Its delivery reported after the click: a gateway checking it on the way.
    [$scanned, $scannedToken, $scannedLink] = trackedEmail(['status' => MessageStatus::Delivered, 'sent_at' => now()->subMinute(), 'delivered_at' => now()->addSeconds(20)]);
    $gmail = ['User-Agent' => 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)'];

    $this->get(Links::open($justSentToken), $gmail);
    $this->get(Links::click($link->key, $justSentToken), ['User-Agent' => DESKTOP_CHROME]);
    $this->get(Links::open($readToken), $gmail);
    $this->get(Links::click($scannedLink->key, $scannedToken), ['User-Agent' => DESKTOP_CHROME]);
    applyHits();

    $machine = fn (CampaignMessage $message, FeedbackType $type): bool => (bool) ProviderEvent::query()->where('message_id', $message->id)->where('type', $type)->sole()->is_bot;

    expect($machine($justSent, FeedbackType::Opened))->toBeTrue()
        ->and($machine($justSent, FeedbackType::Clicked))->toBeTrue()
        ->and($machine($read, FeedbackType::Opened))->toBeFalse()
        ->and($machine($scanned, FeedbackType::Clicked))->toBeTrue()
        ->and($justSent->fresh())->opened_at->toBeNull()->clicked_at->toBeNull()
        ->and($read->fresh()?->opened_at)->not->toBeNull()
        ->and($scanned->fresh()?->clicked_at)->toBeNull();
});

it('redirects a tracked link to its address and records the click with that address', function (): void {
    [$message, $token, $link] = trackedEmail();

    $click = $this->get(Links::click($link->key, $token), ['User-Agent' => DESKTOP_CHROME]);
    applyHits();

    $click->assertStatus(302)
        ->assertRedirect('https://shop.test/offer?a=1')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeaderMissing('Set-Cookie');

    expect($click->baseResponse->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and(ProviderEvent::query()->sole())
        ->driver->toBe('campaigns')
        ->type->toBe(FeedbackType::Clicked)
        ->and(ProviderEvent::query()->sole()->payload['url'])->toBe('https://shop.test/offer?a=1')
        ->and($message->fresh()?->clicked_at?->getTimestamp())->toBe(now()->getTimestamp());
});

it('answers an unknown link with the invalid page, and records nothing for a token it doesn\'t know or another email\'s link', function (): void {
    [, $token, $link] = trackedEmail();
    [, $otherToken] = trackedEmail();

    $this->get('/campaigns/l/'.str_repeat('a', 22).'/'.$token)->assertNotFound();
    $this->get(Links::click($link->key, Token::generate()))->assertRedirect('https://shop.test/offer?a=1');
    $this->get(Links::click($link->key, $otherToken))->assertRedirect('https://shop.test/offer?a=1');
    $this->get(Links::open(Token::generate()))->assertOk()->assertHeader('Content-Type', 'image/gif');
    // A key that isn't one, by its length.
    $this->get('/campaigns/l/short/'.$token)->assertNotFound();

    expect(ProviderEvent::query()->count())->toBe(0);
});

it('stores a test email\'s hits against it, changing nothing else', function (): void {
    $revision = Revision::factory()->withContent('en', ['html' => '<a href="https://shop.test/offer">Offer</a>{{footer}}'])->create();
    CampaignLink::writeFor($revision);
    $token = Token::generate();
    $address = Subscriber::factory()->create();
    $test = TestSend::factory()->create(['revision_id' => $revision->id, 'email' => $address->email, 'tracking_token_hash' => Token::hash($token), 'sent_at' => now()->subHour()]);
    $link = CampaignLink::query()->sole();

    $this->get(Links::open($token), ['User-Agent' => DESKTOP_CHROME])->assertOk();
    $this->get(Links::click($link->key, $token), ['User-Agent' => DESKTOP_CHROME])->assertRedirect('https://shop.test/offer');
    applyHits();

    expect(ProviderEvent::query()->pluck('test_send_id')->unique()->all())->toBe([$test->id])
        ->and(ProviderEvent::query()->whereNotNull('processed_at')->count())->toBe(2)
        ->and($address->fresh())->last_opened_at->toBeNull()->last_clicked_at->toBeNull();
});

it('records at most a few hits a minute for one email, answering every one', function (): void {
    [$message, $token] = trackedEmail();

    foreach (range(1, 32) as $hit) {
        $this->get(Links::open($token), ['User-Agent' => DESKTOP_CHROME])->assertOk();
    }

    $this->travel(61)->seconds();
    $this->get(Links::open($token), ['User-Agent' => DESKTOP_CHROME])->assertOk();

    expect(ProviderEvent::query()->where('message_id', $message->id)->count())->toBe(31);
});

it('still answers when the database refuses the hit, reporting it without what it held', function (): void {
    [, $token, $link] = trackedEmail();
    Exceptions::fake();
    $this->mock(RecordFeedbackAction::class)->shouldReceive('execute')->andThrow(new PDOException('SQLSTATE[HY000]: insert values (reader@example.org)'));

    $this->get(Links::click($link->key, $token))->assertRedirect('https://shop.test/offer?a=1');
    $this->get(Links::open($token))->assertOk();

    Exceptions::assertReported(fn (DatabaseFailedException $exception): bool => ! str_contains($exception->getMessage(), 'reader@example.org') && $exception->getPrevious() === null);
});

it('still answers when anything else fails while recording, reporting it', function (): void {
    [, $token, $link] = trackedEmail();
    Exceptions::fake();
    // As when the queue the hit's job goes on is down.
    $this->mock(RecordFeedbackAction::class)->shouldReceive('execute')->andThrow(new RuntimeException('The queue is down.'));

    $this->get(Links::click($link->key, $token))->assertRedirect('https://shop.test/offer?a=1');
    $this->get(Links::open($token))->assertOk()->assertHeader('Content-Type', 'image/gif');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The queue is down.');
});

it('records nothing for an email whose tracking token was cleared as it went back to pending', function (): void {
    [$message, $token] = trackedEmail();

    $message->forceFill(['status' => MessageStatus::Pending, ...CampaignMessage::UNCLAIMED])->save();
    $this->get(Links::open($token))->assertOk();

    expect(ProviderEvent::query()->count())->toBe(0);
});

it('keeps every page of the package out of caches, the pixel keeping its own stricter header', function (): void {
    [, $token] = trackedEmail();

    $pixel = $this->get(Links::open($token))->baseResponse;
    $page = $this->get(Links::webVersion(Token::generate()))->baseResponse;

    expect($pixel->headers->get('Cache-Control'))->toContain('max-age=0')->toContain('no-cache')
        ->and($page->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($page->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($page->getStatusCode())->toBe(Response::HTTP_NOT_FOUND);
});

it('masks a tracked link\'s key and token, and the pixel\'s, in a stored address', function (): void {
    $key = str_repeat('K', 22);
    $token = Token::generate();

    expect(RecordFeedbackAction::storedUrl('https://app.test/campaigns/l/'.$key.'/'.$token.'?x=1'))->toBe('https://app.test/campaigns/l/{key}/{token}?x=1')
        ->and(RecordFeedbackAction::storedUrl('https://app.test/campaigns/o/'.$token))->toBe('https://app.test/campaigns/o/{token}')
        ->and(RecordFeedbackAction::storedUrl('https://app.test/campaigns/u/'.$token))->toBe('https://app.test/campaigns/u/{token}');
});
