<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\CampaignsServiceProvider;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Reports\CampaignReport;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\SmtpDriver;
use Mozex\Campaigns\Tests\Fixtures\Smtp\ScriptedStream;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

it('hands the email to the server in one conversation, its service headers left out', function (): void {
    $stream = new ScriptedStream(ScriptedStream::sending());
    scriptedSmtp($stream);

    $receipt = app(SmtpDriver::class)->send(outgoing());

    expect($receipt->providerMessageId)->toBe('4ABC123')
        ->and($stream->remaining())->toBe([])
        ->and(array_values(array_diff($stream->commands, ['NOOP'])))->toBe(['EHLO', 'MAIL', 'RCPT', 'DATA', '.'])
        // Its own NOOP after starting, before the email.
        ->and($stream->commands[1])->toBe('NOOP')
        ->and($stream->data)->toContain('List-Unsubscribe: <https://app.example.com/campaigns/u/abc>')
        ->and($stream->data)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click')
        ->and($stream->data)->toContain('Subject: Spring sale')
        ->and($stream->data)->toContain('Reply-To: help@example.com')
        ->and($stream->data)->not->toContain('X-Tag')
        ->and($stream->data)->not->toContain('X-Metadata')
        ->and($stream->data)->not->toContain('0a1b2c3d-0000-4000-8000-000000000001');
});

it('reads a refusal by its reply code alone, never quoting the server', function (string $command, string $reply, SendFailure $failure, ?DeliveryFailure $bounce): void {
    scriptedSmtp(new ScriptedStream(ScriptedStream::sending([$command => $reply])));

    $failed = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    expect($failed->failure)->toBe($failure)
        ->and($failed->deliveryFailure)->toBe($bounce)
        ->and($failed->status)->toBe((int) substr($reply, 0, 3))
        ->and($failed->getMessage())->not->toContain('@')
        ->and($failed->getMessage())->not->toContain('Recipient')
        ->and($failed->getPrevious())->toBeNull();
})->with([
    'a sender greylisted' => ['MAIL', '451 4.7.1 Greylisted, try again later', SendFailure::NotAccepted, null],
    'a server closing' => ['MAIL', '421 4.7.0 Too many connections', SendFailure::NotAccepted, null],
    'a mailbox full for now' => ['RCPT', '452 4.2.2 <reader@example.org>: Mailbox full', SendFailure::NotAccepted, null],
    'the data put off' => ['.', '451 4.3.0 Temporary queue failure', SendFailure::NotAccepted, null],
    'a mailbox that doesn\'t exist' => ['RCPT', '550 5.1.1 <reader@example.org>: Recipient address rejected: User unknown', SendFailure::Rejected, DeliveryFailure::HardBounce],
    'a system that doesn\'t exist' => ['RCPT', '550 5.1.2 <reader@example.org>: Recipient address rejected: Domain not found', SendFailure::Rejected, DeliveryFailure::HardBounce],
    'an address that can\'t be one' => ['RCPT', '553 5.1.3 <reader@example.org>: Recipient address rejected: Bad syntax', SendFailure::Rejected, DeliveryFailure::HardBounce],
    'a domain taking no mail' => ['RCPT', '556 5.1.10 <reader@example.org>: Recipient address rejected: Domain does not accept mail', SendFailure::Rejected, DeliveryFailure::HardBounce],
    'a mailbox that doesn\'t exist, said over lines' => ['RCPT', "550-5.1.1 The email account that you tried to reach does not exist.\r\n550 5.1.1 Please try double-checking the recipient's email address.", SendFailure::Rejected, DeliveryFailure::HardBounce],
    'a recipient refused by policy' => ['RCPT', '550 5.7.1 <reader@example.org>: Relay access denied', SendFailure::Rejected, null],
    'a refusal with no enhanced code' => ['RCPT', '550 Requested action not taken: mailbox unavailable', SendFailure::Rejected, null],
    'a sender refused' => ['MAIL', '553 5.7.1 <news@news.example.com>: Sender address rejected: not owned by user', SendFailure::DomainRefused, null],
    'a sender refused with a mailbox code, which names no recipient' => ['MAIL', '553 5.1.3 <news@news.example.com>: Sender address rejected: bad syntax', SendFailure::DomainRefused, null],
    'a mailbox that doesn\'t exist, said at the email\'s end' => ['.', '550 5.1.1 <reader@example.org>: Recipient address rejected: User unknown', SendFailure::Rejected, DeliveryFailure::HardBounce],
    'the data command refused' => ['DATA', '554 5.5.1 Error: no valid recipients', SendFailure::Rejected, null],
    'the content refused' => ['.', '554 5.7.1 Message rejected as spam', SendFailure::Rejected, null],
    'the email too large' => ['.', '552 5.3.4 Message size exceeds fixed limit', SendFailure::Rejected, null],
]);

it('takes nothing as sent when the server can\'t be reached, and refuses the sender when its login is', function (): void {
    scriptedSmtp(new ScriptedStream(ScriptedStream::connecting(['CONNECT' => null])));
    $unreachable = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    scriptedSmtp(new ScriptedStream(ScriptedStream::connecting(['CONNECT' => '421 4.3.2 Service not available'])));
    $busy = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    $stream = new ScriptedStream([
        ...ScriptedStream::connecting(['EHLO' => "250-relay.example.com\r\n250 AUTH LOGIN"]),
        ['AUTH', '535 5.7.8 Error: authentication failed: news@news.example.com'],
    ]);
    scriptedSmtp($stream)->setUsername('news@news.example.com');
    $refused = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    expect($unreachable->failure)->toBe(SendFailure::NotAccepted)
        ->and($unreachable->status)->toBeNull()
        ->and($busy->failure)->toBe(SendFailure::NotAccepted)
        ->and($busy->status)->toBe(421)
        ->and($refused->failure)->toBe(SendFailure::DomainRefused)
        ->and($refused->status)->toBe(535)
        ->and($refused->getMessage())->not->toContain('@')
        ->and($stream->remaining())->toBe([])
        ->and($stream->commands)->not->toContain('MAIL');
});

it('leaves an email unclear when the server stops answering once it is being sent', function (string $command): void {
    scriptedSmtp(new ScriptedStream(ScriptedStream::sending([$command => null])));

    $failed = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    expect($failed->failure)->toBe(SendFailure::Unclear)
        ->and($failed->status)->toBeNull();
})->with(['RCPT', 'DATA', '.']);

it('starts a connection the server dropped again before sending, never failing the email midway', function (?string $noop): void {
    $stream = new ScriptedStream([
        ...ScriptedStream::sending(),
        ['NOOP', $noop],
        ...ScriptedStream::sending(['.' => '250 2.0.0 Ok: queued as 5DEF456']),
    ]);
    scriptedSmtp($stream);

    $first = app(SmtpDriver::class)->send(outgoing());
    $second = app(SmtpDriver::class)->send(outgoing());

    expect([$first->providerMessageId, $second->providerMessageId])->toBe(['4ABC123', '5DEF456'])
        ->and($stream->connections)->toBe(2)
        ->and($stream->remaining())->toBe([]);
})->with([
    'gone' => [null],
    'answering with its farewell' => ['421 4.4.2 relay.example.com Error: timeout exceeded'],
]);

it('never lets Symfony start the connection again after an email went, which would read a refused restart as that email\'s failure', function (): void {
    $stream = new ScriptedStream([...ScriptedStream::sending(), ['CONNECT', '421 4.7.0 Too many connections, try again later']]);
    // Symfony's default restarts after 100 emails; after each one here.
    scriptedSmtp($stream)->setRestartThreshold(1);

    $receipt = app(SmtpDriver::class)->send(outgoing());

    expect($receipt->providerMessageId)->toBe('4ABC123')
        ->and($stream->connections)->toBe(1)
        ->and($stream->remaining())->toBe(['CONNECT']);
});

it('refuses an address with characters outside ASCII before the @ for good when the server offers no SMTPUTF8, sending nothing', function (): void {
    $stream = new ScriptedStream(ScriptedStream::connecting());
    scriptedSmtp($stream);

    $failed = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing(to: 'josé@example.org')));

    expect($failed->failure)->toBe(SendFailure::Rejected)
        ->and($failed->deliveryFailure)->toBeNull()
        ->and($failed->getMessage())->not->toContain('@example')
        ->and($stream->commands)->not->toContain('MAIL');
});

it('keeps a live connection for the next email', function (): void {
    $stream = new ScriptedStream([...ScriptedStream::sending(), ...ScriptedStream::email()]);
    scriptedSmtp($stream);

    app(SmtpDriver::class)->send(outgoing());
    app(SmtpDriver::class)->send(outgoing());

    expect($stream->connections)->toBe(1)
        ->and($stream->remaining())->toBe([]);
});

it('sends only through an SMTP mailer, or one that delivers nothing outside production', function (): void {
    config()->set('mail.mailers.campaigns', ['transport' => 'sendmail', 'path' => '/usr/sbin/sendmail -bs -i']);
    $sendmail = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    Mail::purge('campaigns');
    config()->set('mail.mailers.campaigns', ['transport' => 'array']);
    $receipt = app(SmtpDriver::class)->send(outgoing());

    app()->detectEnvironment(fn (): string => 'production');
    $production = sendFailure(fn () => app(SmtpDriver::class)->send(outgoing()));

    expect($sendmail->failure)->toBe(SendFailure::DomainRefused)
        ->and($sendmail->getMessage())->toBe('The campaigns mailer "campaigns" must send through SMTP: give it the smtp transport (or the log or array mailer locally).')
        ->and($receipt->providerMessageId)->not->toBe('')
        ->and($production->failure)->toBe(SendFailure::DomainRefused)
        ->and($production->getMessage())->toContain('delivers nothing');
});

it('has its mailer built with a time limit on each read, set as the package registers, unless the app set its own', function (): void {
    config()->set('mail.mailers.campaigns', ['transport' => 'smtp', 'host' => 'relay.example.com', 'port' => 587, 'timeout' => null]);
    config()->set('mail.mailers.patient', ['transport' => 'smtp', 'host' => 'relay.example.com', 'port' => 587, 'timeout' => 45]);
    $provider = new CampaignsServiceProvider(app());

    $provider->packageRegistered();
    $bounded = Mail::mailer('campaigns')->getSymfonyTransport();

    config()->set('campaigns.mail.mailer', 'patient');
    $provider->packageRegistered();
    $patient = Mail::mailer('patient')->getSymfonyTransport();

    expect($bounded)->toBeInstanceOf(EsmtpTransport::class)
        ->and($bounded->getStream()->getTimeout())->toBe((float) SmtpDriver::TIMEOUT)
        ->and(SmtpDriver::TIMEOUT)->toBe(10)
        ->and($patient->getStream()->getTimeout())->toBe(45.0);
});

it('is a built-in driver that reads nothing back, configured only while it sends', function (): void {
    config()->set('campaigns.driver', 'smtp');
    $drivers = app(DriverManager::class);
    $active = $drivers->active();
    $readsBack = $drivers->readsBack('smtp');

    config()->set('campaigns.driver', 'fake');

    expect($active)->toBeInstanceOf(SmtpDriver::class)
        ->and(DriverManager::abilities($active))->toBe([])
        ->and($readsBack)->toBeFalse()
        ->and($drivers->isConfigured('smtp'))->toBeFalse()
        ->and(config('campaigns.drivers.smtp'))->toBe([]);
});

it('sends a whole campaign, each email ending at sent, its rates over sent', function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
    $campaign = sendingCampaign(3, ['per_minute' => 3]);
    config()->set('campaigns.driver', 'smtp');
    $stream = new ScriptedStream([...ScriptedStream::sending(), ...ScriptedStream::email(), ...ScriptedStream::email()]);
    scriptedSmtp($stream);

    Queue::fake([SendMessageJob::class]);
    app(PumpCampaignsAction::class)->execute();
    Queue::pushed(SendMessageJob::class)->each(fn (SendMessageJob $job) => app()->call([$job, 'handle']));
    app(PumpCampaignsAction::class)->execute();

    $report = CampaignReport::build($campaign->fresh());

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and(CampaignMessage::query()->pluck('status')->unique()->all())->toBe([MessageStatus::Sent])
        ->and(CampaignMessage::query()->pluck('driver')->unique()->all())->toBe(['smtp'])
        ->and($stream->remaining())->toBe([])
        ->and($report['sends'])->toBe(3)
        ->and($report['delivered'])->toBe(0)
        ->and($report['rates']['basis'])->toBe('sent')
        ->and($report['rates']['delivery'])->toBeNull()
        ->and($report['rates']['open'])->toBe(0.0)
        ->and($report['rates']['hard_bounce'])->toBe(0.0);
});
