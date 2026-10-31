<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\SendConfirmationAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Jobs\SendConfirmationJob;
use Mozex\Campaigns\Mail\ConfirmSubscriptionMail;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Support\Token;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;

function pendingOn(Subscriber $subscriber, string $listName = 'Weekly news'): Subscription
{
    return Subscription::factory()
        ->for($subscriber, 'subscriber')
        ->for(CampaignList::factory()->doubleOptIn()->create(['name' => $listName]), 'list')
        ->pending()
        ->create(['confirm_token_hash' => null]);
}

/**
 * Runs a confirmation job as the queue would.
 */
function confirmationJob(int $subscriptionId): void
{
    app()->call([new SendConfirmationJob($subscriptionId), 'handle']);
}

beforeEach(function (): void {
    config()->set('campaigns.enabled', true);
});

it('emails a confirmation link on the links\' host through the active driver, storing only its token\'s hash', function (): void {
    config()->set('app.url', 'https://app.example.com');
    config()->set('campaigns.url', 'https://links.example.com/');
    config()->set('campaigns.mail.from', ['address' => 'news@links.example.com', 'name' => 'Acme News']);
    config()->set('campaigns.mail.reply_to', 'help@example.com');
    app('translator')->addLines(['pages.subscribed.heading' => '¿Darse de baja de :app?', 'mail.confirm.subject' => 'Confirma tu suscripción a :list'], 'es', 'campaigns');
    $subscription = pendingOn(Subscriber::factory()->create(['email' => 'reader@example.com', 'locale' => 'es']));

    expect(app(SendConfirmationAction::class)->execute($subscription))->toBeTrue();

    [$sent] = fakeDriver()->sent();
    preg_match('#https://links\.example\.com/campaigns/c/([A-Za-z0-9]+)#', $sent->html, $link);

    expect($sent->to)->toBe('reader@example.com')
        ->and($sent->fromEmail)->toBe('news@links.example.com')
        ->and($sent->fromName)->toBe('Acme News')
        ->and($sent->replyTo)->toBe('help@example.com')
        ->and($sent->subject)->toBe('Confirma tu suscripción a Weekly news')
        ->and($sent->html)->toContain('lang="es"')
        ->and($sent->text)->toContain($link[0])
        ->and(Token::hash($link[1]))->toBe($subscription->fresh()?->confirm_token_hash);

    // The link it carries opens the confirmation page.
    $this->get(Str::after($link[0], 'https://links.example.com'))->assertOk()->assertSee('Weekly news');
});

it('sends it as no marketing: no unsubscribe header, never tracked, under its own tag and a uuid stored nowhere', function (): void {
    confirmationJob(pendingOn(Subscriber::factory()->create())->id);

    [$sent] = fakeDriver()->sent();

    expect($sent->unsubscribeUrl)->toBeNull()
        ->and($sent->headers())->toBe([])
        ->and($sent->toEmail()->getHeaders()->has('List-Unsubscribe'))->toBeFalse()
        ->and($sent->trackOpens)->toBeFalse()
        ->and($sent->trackClicks)->toBeFalse()
        ->and($sent->isTest)->toBeFalse()
        ->and($sent->tag)->toBe(ConfirmSubscriptionMail::TAG)
        ->and(Str::isUuid($sent->uuid))->toBeTrue()
        ->and(CampaignMessage::query()->where('uuid', $sent->uuid)->exists())->toBeFalse()
        ->and(TestSend::query()->where('uuid', $sent->uuid)->exists())->toBeFalse();
});

it('asks Mailgun, through its driver, to track neither opens nor clicks and to add no unsubscribe header', function (): void {
    $posted = [];
    config()->set('campaigns.driver', 'mailgun');
    config()->set('mail.mailers.campaigns', ['transport' => 'mailgun', 'scheme' => 'api', 'secret' => 'key-test', 'domain' => 'news.example.com', 'endpoint' => 'api.eu.mailgun.net']);
    Mail::mailer('campaigns')->setSymfonyTransport(new MailgunApiTransport('key-test', 'news.example.com', 'eu', new MockHttpClient(function (string $method, string $url, array $options) use (&$posted): MockResponse {
        $body = $options['body'];
        $read = '';

        while (! is_string($body) && ($chunk = $body(8192)) !== '') {
            $read .= $chunk;
        }

        $posted[] = is_string($body) ? $body : $read;

        return new MockResponse('{"id": "<sent@news.example.com>", "message": "Queued. Thank you."}');
    })));

    confirmationJob(pendingOn(Subscriber::factory()->create())->id);

    expect($posted)->toHaveCount(1)
        ->and($posted[0])->toMatch('/name="o:tracking-opens"\s+no\s/')
        ->and($posted[0])->toMatch('/name="o:tracking-clicks"\s+no\s/')
        ->and($posted[0])->toMatch('/name="o:tag"\s+campaigns-confirmation\s/')
        ->and($posted[0])->not->toContain('List-Unsubscribe');
});

it('queues only the subscription\'s id, never the link', function (): void {
    Queue::fake();
    $subscription = pendingOn(Subscriber::factory()->create());

    app(SendConfirmationAction::class)->execute($subscription);

    Queue::assertPushedOn('campaigns', SendConfirmationJob::class, fn (SendConfirmationJob $job): bool => $job->subscriptionId === $subscription->id);

    expect($subscription->fresh()?->confirm_token_hash)->toBeNull();

    // What the queue holds, against the link the job then mails.
    $job = Queue::pushed(SendConfirmationJob::class)->sole();
    $payload = serialize($job);
    app()->call([$job, 'handle']);

    [$sent] = fakeDriver()->sent();
    preg_match('#/campaigns/c/([A-Za-z0-9]+)#', $sent->html, $link);

    expect($link[1])->not->toBe('')
        ->and($payload)->not->toContain($link[1]);
});

it('gives the earlier link and the day back when the email can\'t be sent', function (Closure $breakIt): void {
    $breakIt();
    $subscription = pendingOn(Subscriber::factory()->create(['confirmation_sent_at' => now()]));
    $subscription->forceFill(['confirm_token_hash' => Token::hash('the-earlier-emails-token')])->save();

    $thrown = null;

    try {
        confirmationJob($subscription->id);
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // Failed jobs and error reports keep the message: never the address,
    // never the driver's exception chained.
    expect($thrown)->toBeInstanceOf(CampaignsException::class)
        ->and($thrown?->getMessage())->not->toContain('ann@example.org')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and($subscription->fresh()?->confirm_token_hash)->toBe(Token::hash('the-earlier-emails-token'))
        ->and($subscription->subscriber->fresh()?->confirmation_sent_at)->toBeNull();
})->with([
    'the service refuses it' => fn () => app(DriverManager::class)->swap('fake', new class extends FakeDriver
    {
        public function send(OutgoingMessage $message): SendReceipt
        {
            throw new RuntimeException('550 5.1.1 <ann@example.org>: Recipient address rejected');
        }
    }),
    'the driver isn\'t configured' => fn () => config()->set('campaigns.driver', 'missing'),
]);

it('sends an address one confirmation a day, whichever list asks', function (string $driver): void {
    useDatabase($driver);
    $subscriber = Subscriber::factory()->create();
    $news = pendingOn($subscriber, 'News');
    $digest = pendingOn($subscriber, 'Digest');

    $first = app(SendConfirmationAction::class)->execute($news);
    $sameDay = app(SendConfirmationAction::class)->execute($digest);
    $this->travel(23)->hours();
    $nextMorning = app(SendConfirmationAction::class)->execute($digest);
    $this->travel(2)->hours();
    $nextDay = app(SendConfirmationAction::class)->execute($digest);

    expect([$first, $sameDay, $nextMorning, $nextDay])->toBe([true, false, false, true])
        ->and(fakeDriver()->sent())->toHaveCount(2);
})->with('databases');

it('sends nothing while sending is off, to a blocked address, or for a subscription that isn\'t waiting', function (): void {
    $off = pendingOn(Subscriber::factory()->create());
    $blocked = pendingOn(Subscriber::factory()->blocked()->create());
    $done = Subscription::factory()->for(Subscriber::factory()->create(), 'subscriber')->create(['status' => SubscriptionStatus::Subscribed]);

    config()->set('campaigns.enabled', false);
    $whileOff = app(SendConfirmationAction::class)->execute($off);
    config()->set('campaigns.enabled', true);

    expect($whileOff)->toBeFalse()
        ->and($off->subscriber->fresh()?->confirmation_sent_at)->toBeNull()
        ->and(app(SendConfirmationAction::class)->execute($blocked))->toBeFalse()
        ->and(app(SendConfirmationAction::class)->execute($done))->toBeFalse()
        ->and(fakeDriver()->sent())->toBe([]);

    // Switched off after the action queued it: the day is given back.
    $queued = pendingOn(Subscriber::factory()->create(['confirmation_sent_at' => now()]));
    config()->set('campaigns.enabled', false);
    confirmationJob($queued->id);

    expect($queued->subscriber->fresh()?->confirmation_sent_at)->toBeNull();
});

it('still asks an opted-out person, since confirming is their way back', function (): void {
    $subscription = pendingOn(Subscriber::factory()->optedOut()->create());

    expect(app(SendConfirmationAction::class)->execute($subscription))->toBeTrue()
        ->and(fakeDriver()->sent())->toHaveCount(1);
});

it('sends nothing when the address was blocked or confirmed after the email was queued', function (): void {
    $blocked = pendingOn(Subscriber::factory()->create());
    $confirmed = pendingOn(Subscriber::factory()->create());
    $blocked->subscriber->forceFill(['blocked_at' => now()])->save();
    $confirmed->forceFill(['status' => SubscriptionStatus::Subscribed])->save();

    confirmationJob($blocked->id);
    confirmationJob($confirmed->id);
    confirmationJob(999_999);

    expect($blocked->fresh()?->confirm_token_hash)->toBeNull()
        ->and($confirmed->fresh()?->confirm_token_hash)->toBeNull()
        ->and(fakeDriver()->sent())->toBe([]);
});

it('renders the email with its link and plain text a mail app can show', function (): void {
    config()->set('campaigns.name', 'Acme News');
    config()->set('campaigns.mail.from.address', 'news@news.example.com');

    $sent = (new ConfirmSubscriptionMail('Weekly news', 'https://links.example.com/campaigns/c/abc123abc123abc123'))->outgoing('reader@example.com', 'en');

    expect($sent->subject)->toBe('Confirm your subscription to Weekly news')
        ->and($sent->html)->toContain('Weekly news emails from Acme News')
        ->and($sent->html)->toContain('href="https://links.example.com/campaigns/c/abc123abc123abc123"')
        ->and($sent->text)->toContain("Didn't ask for this?")
        ->and($sent->text)->toContain('https://links.example.com/campaigns/c/abc123abc123abc123')
        ->and($sent->text)->not->toContain('&#039;');
});
