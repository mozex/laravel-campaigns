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
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Token;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

function pendingOn(Subscriber $subscriber, string $listName = 'Weekly news'): Subscription
{
    return Subscription::factory()
        ->for($subscriber, 'subscriber')
        ->for(CampaignList::factory()->doubleOptIn()->create(['name' => $listName]), 'list')
        ->pending()
        ->create(['confirm_token_hash' => null]);
}

beforeEach(function (): void {
    config()->set('campaigns.enabled', true);
});

it('emails a confirmation link on the links\' host, storing only its token\'s hash', function (): void {
    Mail::fake();
    config()->set('app.url', 'https://app.example.com');
    config()->set('campaigns.url', 'https://links.example.com/');
    config()->set('campaigns.mail.from', ['address' => 'news@links.example.com', 'name' => 'Acme News']);
    app('translator')->addLines(['pages.subscribed.heading' => '¿Darse de baja de :app?'], 'es', 'campaigns');
    $subscription = pendingOn(Subscriber::factory()->create(['email' => 'reader@example.com', 'locale' => 'es']));

    expect(app(SendConfirmationAction::class)->execute($subscription))->toBeTrue();

    Mail::assertSent(ConfirmSubscriptionMail::class, function (ConfirmSubscriptionMail $mail) use ($subscription): bool {
        $token = (string) str($mail->url)->after('https://links.example.com/campaigns/c/');

        return $mail->hasTo('reader@example.com')
            && $mail->hasFrom('news@links.example.com', 'Acme News')
            && $mail->hasSubject('Confirm your subscription to Weekly news')
            && $mail->mailer === 'campaigns'
            && $mail->locale === 'es'
            && $mail->listName === 'Weekly news'
            && str_starts_with($mail->url, 'https://links.example.com/campaigns/c/')
            && Token::hash($token) === $subscription->fresh()?->confirm_token_hash;
    });

    // The link it carries opens the confirmation page.
    Mail::assertSent(ConfirmSubscriptionMail::class, function (ConfirmSubscriptionMail $mail): bool {
        $this->get((string) str($mail->url)->after('https://links.example.com'))->assertOk()->assertSee('Weekly news');

        return true;
    });
});

it('queues only the subscription\'s id, never the link', function (): void {
    Queue::fake();
    Mail::fake();
    $subscription = pendingOn(Subscriber::factory()->create());

    app(SendConfirmationAction::class)->execute($subscription);

    Queue::assertPushedOn('campaigns', SendConfirmationJob::class, fn (SendConfirmationJob $job): bool => $job->subscriptionId === $subscription->id);

    expect($subscription->fresh()?->confirm_token_hash)->toBeNull();

    // What the queue holds, against the link the job then mails.
    $job = Queue::pushed(SendConfirmationJob::class)->sole();
    $payload = serialize($job);
    $job->handle();

    Mail::assertSent(ConfirmSubscriptionMail::class, fn (ConfirmSubscriptionMail $mail): bool => ! str_contains($payload, Str::afterLast($mail->url, '/')));
});

it('gives the earlier link and the day back when the email can\'t be sent', function (string $mailer): void {
    Mail::extend('throwing', fn (): TransportInterface => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('550 5.1.1 <ann@example.org>: Recipient address rejected');
        }

        public function __toString(): string
        {
            return 'throwing://';
        }
    });
    config()->set('mail.mailers.broken', ['transport' => 'throwing']);
    config()->set('campaigns.mail.mailer', $mailer);
    $subscription = pendingOn(Subscriber::factory()->create(['confirmation_sent_at' => now()]));
    $subscription->forceFill(['confirm_token_hash' => Token::hash('the-earlier-emails-token')])->save();

    $thrown = null;

    try {
        (new SendConfirmationJob($subscription->id))->handle();
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    // Failed jobs and error reports keep the message: never the address,
    // never the mailer's exception chained.
    expect($thrown)->toBeInstanceOf(CampaignsException::class)
        ->and($thrown?->getMessage())->not->toContain('ann@example.org')
        ->and($thrown?->getPrevious())->toBeNull()
        ->and($subscription->fresh()?->confirm_token_hash)->toBe(Token::hash('the-earlier-emails-token'))
        ->and($subscription->subscriber->fresh()?->confirmation_sent_at)->toBeNull();
})->with([
    'the mail server refuses it' => 'broken',
    'the mailer isn\'t configured' => 'missing',
]);

it('sends an address one confirmation a day, whichever list asks', function (string $driver): void {
    useDatabase($driver);
    Mail::fake();
    $subscriber = Subscriber::factory()->create();
    $news = pendingOn($subscriber, 'News');
    $digest = pendingOn($subscriber, 'Digest');

    $first = app(SendConfirmationAction::class)->execute($news);
    $sameDay = app(SendConfirmationAction::class)->execute($digest);
    $this->travel(23)->hours();
    $nextMorning = app(SendConfirmationAction::class)->execute($digest);
    $this->travel(2)->hours();
    $nextDay = app(SendConfirmationAction::class)->execute($digest);

    expect([$first, $sameDay, $nextMorning, $nextDay])->toBe([true, false, false, true]);

    Mail::assertSentCount(2);
})->with('databases');

it('sends nothing while sending is off, to a blocked address, or for a subscription that isn\'t waiting', function (): void {
    Mail::fake();
    $off = pendingOn(Subscriber::factory()->create());
    $blocked = pendingOn(Subscriber::factory()->blocked()->create());
    $done = Subscription::factory()->for(Subscriber::factory()->create(), 'subscriber')->create(['status' => SubscriptionStatus::Subscribed]);

    config()->set('campaigns.enabled', false);
    $whileOff = app(SendConfirmationAction::class)->execute($off);
    config()->set('campaigns.enabled', true);

    expect($whileOff)->toBeFalse()
        ->and($off->subscriber->fresh()?->confirmation_sent_at)->toBeNull()
        ->and(app(SendConfirmationAction::class)->execute($blocked))->toBeFalse()
        ->and(app(SendConfirmationAction::class)->execute($done))->toBeFalse();

    Mail::assertNothingSent();
});

it('still asks an opted-out person, since confirming is their way back', function (): void {
    Mail::fake();
    $subscription = pendingOn(Subscriber::factory()->optedOut()->create());

    expect(app(SendConfirmationAction::class)->execute($subscription))->toBeTrue();

    Mail::assertSentCount(1);
});

it('sends nothing when the address was blocked or confirmed after the email was queued', function (): void {
    Mail::fake();
    $blocked = pendingOn(Subscriber::factory()->create());
    $confirmed = pendingOn(Subscriber::factory()->create());
    $blocked->subscriber->forceFill(['blocked_at' => now()])->save();
    $confirmed->forceFill(['status' => SubscriptionStatus::Subscribed])->save();

    (new SendConfirmationJob($blocked->id))->handle();
    (new SendConfirmationJob($confirmed->id))->handle();
    (new SendConfirmationJob(999_999))->handle();

    expect($blocked->fresh()?->confirm_token_hash)->toBeNull()
        ->and($confirmed->fresh()?->confirm_token_hash)->toBeNull();

    Mail::assertNothingSent();
});

it('renders the email with its link and plain text a mail app can show', function (): void {
    config()->set('campaigns.name', 'Acme News');
    $mail = new ConfirmSubscriptionMail('Weekly news', 'https://links.example.com/campaigns/c/abc123abc123abc123');

    $mail->assertSeeInHtml('Weekly news emails from Acme News')
        ->assertSeeInHtml('href="https://links.example.com/campaigns/c/abc123abc123abc123"', false)
        ->assertSeeInText("Didn't ask for this?", false)
        ->assertSeeInText('https://links.example.com/campaigns/c/abc123abc123abc123');
});
