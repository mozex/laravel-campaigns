<?php

declare(strict_types=1);

use Aws\Api\ApiProvider;
use Aws\CommandInterface;
use Aws\Exception\CredentialsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\CampaignsServiceProvider;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Reports\DomainSummary;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\SesDriver;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\Suppression;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

beforeEach(function (): void {
    if (! class_exists(SesV2Client::class)) {
        $this->markTestSkipped('The ses driver needs the AWS SDK for PHP (aws/aws-sdk-php), which isn\'t installed.');
    }

    Http::preventStrayRequests();
});

/**
 * A campaigns mailer on Laravel's ses-v2 transport whose SDK calls go to
 * $handler (the SDK's MockHandler), never AWS.
 *
 * @param  array<string, mixed>  $settings
 */
function sesMailer(MockHandler $handler, array $settings = []): void
{
    config()->set('mail.mailers.campaigns', [
        'transport' => 'ses-v2',
        'key' => 'AKIDEXAMPLE',
        'secret' => 'secret-example',
        'region' => 'eu-west-1',
        'retries' => 0,
        'handler' => $handler,
        ...$settings,
    ]);

    Mail::purge('campaigns');
}

/**
 * An error the SDK would throw for a call, its message quoting an address
 * the way AWS's own messages and the request's address can.
 */
function sesError(?string $code, ?int $status, bool $connection = false): Closure
{
    return fn (CommandInterface $command): SesV2Exception => new SesV2Exception(
        'Error executing "'.$command->getName().'" on "https://email.eu-west-1.amazonaws.com/v2/email/suppression/addresses/reader%40example.org"; AWS HTTP error: Email address is not verified: reader@example.org',
        $command,
        array_filter([
            'code' => $code,
            'response' => $status === null ? null : new Response($status),
            'connection_error' => $connection,
            'message' => 'Email address is not verified. The following identities failed the check in region EU-WEST-1: reader@example.org',
        ]),
    );
}

/**
 * The events SES's webhook request reports, as the driver reads them.
 *
 * @param  array<string, mixed>  $body
 * @return list<Feedback>
 */
function sesFeedback(array $body): array
{
    $request = Request::create('/campaigns/webhooks/ses', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

    return app(SesDriver::class)->parseWebhook($request);
}

describe('sending', function (): void {
    it('sends the email raw through SES\'s v2 API with its uuid as a message tag and no tag header, and keeps SES\'s own id', function (): void {
        $handler = new MockHandler([new Result(['MessageId' => '0102018f-campaign-000000']), new Result(['MessageId' => '0102018f-test-000000'])]);
        sesMailer($handler, ['options' => ['ConfigurationSetName' => 'marketing']]);

        $receipt = app(SesDriver::class)->send(outgoing());
        $command = $handler->getLastCommand();
        $raw = $command['Content']['Raw']['Data'];

        $test = app(SesDriver::class)->send(outgoing(isTest: true));

        expect($receipt->providerMessageId)->toBe('0102018f-campaign-000000')
            ->and($test->providerMessageId)->toBe('0102018f-test-000000')
            ->and($command->getName())->toBe('SendEmail')
            ->and($command['EmailTags'])->toBe([['Name' => 'campaign-message', 'Value' => '0a1b2c3d-0000-4000-8000-000000000001']])
            ->and($handler->getLastCommand()['EmailTags'])->toBe([['Name' => 'campaign-test', 'Value' => '0a1b2c3d-0000-4000-8000-000000000001']])
            ->and($command['ConfigurationSetName'])->toBe('marketing')
            ->and($command['ConfigurationOverrides'])->toBe(['Tracking' => ['OpenTrackingEnabled' => 'DISABLED', 'ClickTrackingEnabled' => 'DISABLED']])
            ->and($handler->getLastCommand()['ConfigurationOverrides']['Tracking']['ClickTrackingEnabled'])->toBe('DISABLED')
            ->and($command['Destination']['ToAddresses'])->toBe(['reader@example.org'])
            ->and($raw)->toContain('List-Unsubscribe: <https://app.example.com/campaigns/u/abc>')
            ->and($raw)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click')
            ->and($raw)->toContain('Subject: Spring sale')
            ->and($raw)->not->toContain('X-Tag')
            ->and($raw)->not->toContain('spring-sale');
    });

    it('tells what SES\'s answer means for the email by its code and status, without its words or the address', function (?string $code, int $status, SendFailure $failure): void {
        sesMailer(new MockHandler([sesError($code, $status)]));

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe($failure)
            ->and($failed->status)->toBe($status)
            ->and($failed->deliveryFailure)->toBeNull()
            ->and($failed->getMessage())->toBe("Amazon SES answered {$status} (".($code ?? 'unknown').').')
            ->and($failed->getPrevious())->toBeNull();
    })->with([
        'throttled' => ['TooManyRequestsException', 429, SendFailure::NotAccepted],
        'throttled, the older code' => ['ThrottlingException', 400, SendFailure::NotAccepted],
        'a request it never received whole' => ['RequestTimeoutException', 408, SendFailure::NotAccepted],
        'its servers failing' => ['InternalFailure', 500, SendFailure::NotAccepted],
        'unavailable' => ['ServiceUnavailable', 503, SendFailure::NotAccepted],
        'a gateway that timed out, which may have handed it on' => [null, 504, SendFailure::Unclear],
        'a suspended account' => ['AccountSuspendedException', 400, SendFailure::DomainRefused],
        'paused sending' => ['SendingPausedException', 400, SendFailure::DomainRefused],
        'an unverified MAIL FROM domain' => ['MailFromDomainNotVerifiedException', 400, SendFailure::DomainRefused],
        'a configuration set it doesn\'t have' => ['NotFoundException', 404, SendFailure::DomainRefused],
        'credentials it refuses' => ['UnrecognizedClientException', 403, SendFailure::DomainRefused],
        'a denied action' => ['AccessDeniedException', 403, SendFailure::DomainRefused],
        'a From address it hasn\'t verified, or refused content, either failing every email' => ['MessageRejected', 400, SendFailure::DomainRefused],
        'a standing limit of the account' => ['LimitExceededException', 400, SendFailure::DomainRefused],
        'a field it can\'t take' => ['BadRequestException', 400, SendFailure::Rejected],
        'anything else' => ['SomethingNewException', 400, SendFailure::Unclear],
    ]);

    it('treats a connection error as unclear, never as not taken, since it can\'t tell whether the request went', function (): void {
        sesMailer(new MockHandler([sesError(null, null, connection: true), sesError('ServiceUnavailable', 503, connection: true)]));

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));
        // The connection's failure outranks a status read from a broken answer.
        $broken = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe(SendFailure::Unclear)
            ->and($failed->status)->toBeNull()
            ->and($failed->getMessage())->not->toContain('reader@example.org')
            ->and($failed->getPrevious())->toBeNull()
            ->and($broken->failure)->toBe(SendFailure::Unclear);
    });

    it('refuses for the domain when the SDK finds no credentials, since nothing went', function (): void {
        sesMailer(new MockHandler([new CredentialsException('No credentials for reader@example.org.')]));

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe(SendFailure::DomainRefused)
            ->and($failed->getMessage())->not->toContain('reader@example.org')
            ->and($failed->getPrevious())->toBeNull();
    });

    it('reports a listener failing after SES took the email, and reads the send as unclear', function (): void {
        Exceptions::fake();
        sesMailer(new MockHandler([new Result(['MessageId' => '0102018f-campaign-000000'])]));
        Event::listen(MessageSent::class, fn () => throw new RuntimeException('A listener of the app failed.'));

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe(SendFailure::Unclear)
            ->and($failed->getPrevious())->toBeNull();

        Exceptions::assertReported(RuntimeException::class);
    });

    // Every transport here points nowhere real, and the SES ones answer
    // from a mock that has nothing queued, so a guard that stopped refusing
    // could never send.
    it('refuses for the domain, before anything goes out, a mailer it can\'t drive or one whose SDK retries', function (Closure $setUp, string $says): void {
        $setUp();

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe(SendFailure::DomainRefused)
            ->and($failed->getMessage())->toContain($says);
    })->with([
        'retries left to the SDK' => [fn () => sesMailer(new MockHandler, ['retries' => null]), "'retries' => 0"],
        'retries above 0' => [fn () => sesMailer(new MockHandler, ['retries' => 3]), "'retries' => 0"],
        'SES\'s first API' => [fn () => config()->set('mail.mailers.campaigns', ['transport' => 'ses', 'key' => 'AKIDEXAMPLE', 'secret' => 'secret-example', 'region' => 'eu-west-1']), 'the ses-v2 transport'],
        'an SMTP transport' => [function (): void {
            config()->set('mail.mailers.campaigns', ['transport' => 'array']);
            Mail::mailer('campaigns')->setSymfonyTransport(new EsmtpTransport('127.0.0.1', 9));
        }, 'the ses-v2 transport'],
        'none defined' => [fn () => config()->set('mail.mailers.campaigns', []), "can't be used"],
        'the log mailer in production' => [function (): void {
            config()->set('mail.mailers.campaigns', ['transport' => 'log']);
            app()->detectEnvironment(fn (): string => 'production');
        }, 'delivers nothing'],
    ]);

    it('refuses an SDK too old to turn SES\'s own tracking off, which would drop the override without a word', function (): void {
        $old = function (string $type, string $service, string $version): ?array {
            $model = ApiProvider::defaultProvider()($type, $service, $version);

            if ($type === 'api' && is_array($model)) {
                unset($model['shapes']['SendEmailRequest']['members']['ConfigurationOverrides']);
            }

            return $model;
        };
        sesMailer(new MockHandler, ['api_provider' => $old]);

        $failed = sendFailure(fn () => app(SesDriver::class)->send(outgoing()));

        expect($failed->failure)->toBe(SendFailure::DomainRefused)
            ->and($failed->getMessage())->toContain('aws/aws-sdk-php to 3.393.3 or later');
    });

    it('takes retries turned off in services.ses, which Laravel merges under the mailer\'s own settings', function (): void {
        config()->set('services.ses', ['retries' => 0]);
        sesMailer(new MockHandler([new Result(['MessageId' => '0102018f-campaign-000000'])]));
        config()->set('mail.mailers.campaigns', array_diff_key((array) config('mail.mailers.campaigns'), ['retries' => true]));

        expect(app(SesDriver::class)->send(outgoing())->providerMessageId)->toBe('0102018f-campaign-000000');
    });

    it('sends through the log and array mailers locally', function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'array']);

        expect(app(SesDriver::class)->send(outgoing())->providerMessageId)->not->toBe('');
    });

    it('has its mailer built with time limits, set as the package registers, unless the app set its own here or in services.ses', function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'ses-v2', 'region' => 'eu-west-1']);
        config()->set('mail.mailers.patient', ['transport' => 'ses-v2', 'region' => 'eu-west-1', 'http' => ['timeout' => 90]]);
        config()->set('mail.mailers.shared', ['transport' => 'ses-v2', 'region' => 'eu-west-1']);
        $provider = new CampaignsServiceProvider(app());

        $provider->packageRegistered();

        config()->set('campaigns.mail.mailer', 'patient');
        $provider->packageRegistered();

        config()->set('services.ses.http', ['timeout' => 60]);
        config()->set('campaigns.mail.mailer', 'shared');
        $provider->packageRegistered();

        expect(config('mail.mailers.campaigns.http'))->toBe(SesDriver::HTTP_LIMITS)
            ->and(SesDriver::HTTP_LIMITS)->toBe(['connect_timeout' => 10, 'timeout' => 30])
            ->and(config('mail.mailers.patient.http'))->toBe(['timeout' => 90])
            ->and(config('mail.mailers.shared'))->not->toHaveKey('http');
    });
});

describe('as a driver', function (): void {
    it('is built in, with webhooks, suppressions and daily counts but no logs, and reads back', function (): void {
        config()->set('campaigns.driver', 'ses');
        $drivers = app(DriverManager::class);
        $active = $drivers->active();
        $readsBack = $drivers->readsBack('ses');

        config()->set('campaigns.driver', 'fake');
        $configuredAfter = $drivers->isConfigured('ses');
        config()->set('campaigns.drivers.ses.webhook_secret', 'webhook-secret');

        expect($active)->toBeInstanceOf(SesDriver::class)
            ->and(DriverManager::abilities($active))->toBe(['webhooks', 'suppressions', 'metrics'])
            ->and($readsBack)->toBeTrue()
            ->and($configuredAfter)->toBeFalse()
            ->and($drivers->isConfigured('ses'))->toBeTrue();
    });

    it('keeps its settings under its own env names', function (): void {
        $defaults = require __DIR__.'/../../../config/campaigns.php';
        $config = file_get_contents(__DIR__.'/../../../config/campaigns.php');

        expect($defaults['drivers']['ses'])->toBe(['webhook_secret' => null, 'vdm' => false])
            ->and($config)->toContain("env('CAMPAIGNS_SES_WEBHOOK_SECRET')")
            ->and($config)->toContain("env('CAMPAIGNS_SES_VDM', false)");
    });

    it('counts as not configured without the AWS SDK, so its webhook answers 404 and nothing builds it', function (): void {
        $manager = new class(app()) extends DriverManager
        {
            protected const REQUIRES = ['ses' => 'Aws\Missing\SesV2Client'];

            public int $built = 0;

            protected function createSesDriver(): SesDriver
            {
                $this->built++;

                return parent::createSesDriver();
            }
        };
        app()->instance(DriverManager::class, $manager);
        config()->set('campaigns.drivers.ses.webhook_secret', 'webhook-secret');

        $status = $this->postJson(route('campaigns.webhooks', 'ses'), sesEvent('delivery'), [SesDriver::WEBHOOK_HEADER => 'webhook-secret'])->status();

        config()->set('campaigns.driver', 'ses');

        expect($status)->toBe(404)
            ->and($manager->built)->toBe(0)
            ->and($manager->isConfigured('ses'))->toBeFalse()
            ->and(fn () => $manager->active())->toThrow(CampaignsException::class, 'composer require aws/aws-sdk-php');
    });
});

describe('webhooks', function (): void {
    beforeEach(function (): void {
        $this->travelTo(Carbon::parse('2026-03-02 12:00:00', 'UTC'));
        config()->set('campaigns.drivers.ses.webhook_secret', 'webhook-secret');
        Queue::fake([ProcessFeedbackJob::class]);
    });

    it('takes an event carrying the webhook secret, dated within a day either way, and refuses every other', function (): void {
        $message = CampaignMessage::factory()->create(['driver' => 'ses']);
        $event = fn (array $changes = []): array => sesEvent('delivery', ['detail' => ['mail' => ['tags' => ['campaign-message' => [$message->uuid]]]], ...$changes]);
        $post = fn (array $body, array $headers): int => $this->postJson(route('campaigns.webhooks', 'ses'), $body, $headers)->status();
        $key = [SesDriver::WEBHOOK_HEADER => 'webhook-secret'];

        expect($post($event(), [SesDriver::WEBHOOK_HEADER => 'wrong']))->toBe(406)
            ->and($post($event(), []))->toBe(406)
            ->and($post($event(['time' => now()->subHours(25)->format('Y-m-d\TH:i:s\Z')]), $key))->toBe(406)
            ->and($post($event(['time' => now()->addHours(25)->format('Y-m-d\TH:i:s\Z')]), $key))->toBe(406)
            ->and($post($event(['time' => 'yesterday-ish']), $key))->toBe(406)
            ->and(ProviderEvent::query()->count())->toBe(0)
            ->and($post($event(['time' => now()->subHours(23)->format('Y-m-d\TH:i:s\Z')]), $key))->toBe(200)
            // EventBridge's retry of the same event is stored once.
            ->and($post($event(), $key))->toBe(200)
            ->and(ProviderEvent::query()->sole())
            ->driver->toBe('ses')
            ->provider_event_id->toBe('5f5f3a34-0b1a-4d3b-8c43-6d1f3f0a0003')
            ->message_id->toBe($message->id);
    });

    it('answers 500 while its secret isn\'t set, reported once an hour, so EventBridge retries', function (): void {
        Exceptions::fake();
        config()->set('campaigns.drivers.ses.webhook_secret', null);
        config()->set('campaigns.driver', 'ses');

        $first = $this->postJson(route('campaigns.webhooks', 'ses'), sesEvent('delivery'), [SesDriver::WEBHOOK_HEADER => 'anything'])->status();
        $second = $this->postJson(route('campaigns.webhooks', 'ses'), sesEvent('delivery'), [SesDriver::WEBHOOK_HEADER => 'anything'])->status();

        config()->set('campaigns.driver', 'fake');
        $inactive = $this->postJson(route('campaigns.webhooks', 'ses'), sesEvent('delivery'), [SesDriver::WEBHOOK_HEADER => 'anything'])->status();

        expect([$first, $second, $inactive])->toBe([500, 500, 404]);

        Exceptions::assertReportedCount(1);
    });

    it('reads what each event means for the email', function (string $fixture, array $changes, ?FeedbackType $type, ?DeliveryFailure $failure, ?string $severity): void {
        $feedback = sesFeedback(sesEvent($fixture, ['detail' => $changes]));

        if ($type === null) {
            expect($feedback)->toBe([]);

            return;
        }

        expect($feedback)->toHaveCount(1)
            ->and($feedback[0]->type)->toBe($type)
            ->and($feedback[0]->failure)->toBe($failure)
            ->and($feedback[0]->severity)->toBe($severity)
            ->and($feedback[0]->replayKey)->toBeNull();
    })->with([
        'sent' => ['send', [], FeedbackType::Accepted, null, null],
        'delivered' => ['delivery', [], FeedbackType::Delivered, null, null],
        'a hard bounce' => ['bounce', [], FeedbackType::Failed, DeliveryFailure::HardBounce, 'permanent'],
        'no such mailbox' => ['bounce', ['bounce' => ['bounceSubType' => 'NoEmail']], FeedbackType::Failed, DeliveryFailure::HardBounce, 'permanent'],
        'held by SES\'s global list, which SES counts as a bounce' => ['bounce', ['bounce' => ['bounceSubType' => 'Suppressed']], FeedbackType::Failed, DeliveryFailure::HardBounce, 'permanent'],
        'held by the account\'s list' => ['bounce', ['bounce' => ['bounceSubType' => 'OnAccountSuppressionList']], FeedbackType::Failed, DeliveryFailure::SuppressedBounce, 'permanent'],
        'held by a tenant\'s list' => ['bounce', ['bounce' => ['bounceSubType' => 'OnTenantSuppressionList']], FeedbackType::Failed, DeliveryFailure::SuppressedBounce, 'permanent'],
        'SES\'s own validation guessing' => ['bounce', ['bounce' => ['bounceSubType' => 'EmailValidationSuppressed']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'given up after soft bounces' => ['bounce', ['bounce' => ['bounceType' => 'Transient']], FeedbackType::Failed, DeliveryFailure::SoftBounce, 'permanent'],
        'a full mailbox' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MailboxFull']], FeedbackType::Failed, DeliveryFailure::SoftBounce, 'permanent'],
        'too large' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MessageTooLarge']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'its content refused' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'ContentRejected']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'an attachment refused' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'AttachmentRejected']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'the sender\'s own time limit' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'CustomTimeoutExceeded']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'undetermined' => ['bounce', ['bounce' => ['bounceType' => 'Undetermined', 'bounceSubType' => 'Undetermined']], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'a spam report' => ['complaint', [], FeedbackType::Complained, null, null],
        'a complaint the account\'s list stopped' => ['complaint', ['complaint' => ['complaintSubType' => 'OnAccountSuppressionList']], FeedbackType::Failed, DeliveryFailure::SuppressedComplaint, 'permanent'],
        'a report that it isn\'t spam' => ['complaint', ['complaint' => ['complaintFeedbackType' => 'not-spam']], FeedbackType::Accepted, null, null],
        'an authentication failure report' => ['complaint', ['complaint' => ['complaintFeedbackType' => 'auth-failure']], FeedbackType::Accepted, null, null],
        'a virus' => ['reject', [], FeedbackType::Failed, DeliveryFailure::Other, 'permanent'],
        'delayed' => ['delivery-delay', [], FeedbackType::Failed, DeliveryFailure::Deferred, 'temporary'],
        'delayed as spam' => ['delivery-delay', ['deliveryDelay' => ['delayType' => 'SpamDetected']], FeedbackType::Failed, DeliveryFailure::SenderDeferred, 'temporary'],
        'delayed by the sending address' => ['delivery-delay', ['deliveryDelay' => ['delayType' => 'IPFailure']], FeedbackType::Failed, DeliveryFailure::SenderDeferred, 'temporary'],
        'delayed by SES\'s own trouble' => ['delivery-delay', ['deliveryDelay' => ['delayType' => 'InternalFailure']], FeedbackType::Failed, DeliveryFailure::SenderDeferred, 'temporary'],
        'deferred by SES' => ['delivery-delay', ['deliveryDelay' => ['delayType' => 'SendingDeferral']], FeedbackType::Failed, DeliveryFailure::SenderDeferred, 'temporary'],
        'a subscription change' => ['subscription', [], FeedbackType::Accepted, null, null],
        'an open, which the package tracks itself' => ['open', [], null, null, null],
        'a click, which the package tracks itself' => ['click', [], null, null, null],
        'a template that failed to render' => ['rendering-failure', [], null, null, null],
        'an unknown type' => ['send', ['eventType' => 'Something'], null, null, null],
    ]);

    it('finds the email by its uuid tag or SES\'s id, dated by the event\'s own time, keeping no address, IP, header or agent', function (): void {
        $feedback = sesFeedback(sesEvent('bounce', ['detail' => [
            'mail' => ['tags' => ['campaign-message' => ['0A1B2C3D-0000-4000-8000-000000000001'], 'campaign-test' => ['0a1b2c3d-0000-4000-8000-000000000002']]],
            'bounce' => ['bouncedRecipients' => [['emailAddress' => 'recipient@example.com', 'status' => '5.1.1', 'action' => 'failed', 'diagnosticCode' => 'smtp; 550 5.1.1 <recipient@example.com>: user unknown']]],
        ]]))[0];
        $complaint = sesFeedback(sesEvent('complaint'))[0];
        $open = json_encode(sesFeedback(sesEvent('delivery'))[0], JSON_THROW_ON_ERROR);

        expect($feedback->providerEventId)->toBe('5f5f3a34-0b1a-4d3b-8c43-6d1f3f0a0001')
            ->and($feedback->messageUuid)->toBe('0A1B2C3D-0000-4000-8000-000000000001')
            ->and($feedback->testSendUuid)->toBe('0a1b2c3d-0000-4000-8000-000000000002')
            ->and($feedback->providerMessageId)->toBe('EXAMPLE7c191be45-e9aedb9a-02f9-4d12-a87d-dd0099a07f8a-000000')
            ->and($feedback->occurredAt->format('Y-m-d H:i:s.v'))->toBe('2017-08-05 00:41:02.669')
            ->and($feedback->code)->toBe(550)
            ->and($feedback->reason)->toBe('General')
            ->and($feedback->payload)->toBe([
                'bounce-type' => 'Permanent',
                'bounce-subtype' => 'General',
                'status' => '5.1.1',
                'action' => 'failed',
                'diagnostic-code' => 'smtp; 550 5.1.1 <[address]>: user unknown',
            ])
            ->and($complaint->payload)->toBe(['feedback-type' => 'abuse'])
            ->and($complaint->occurredAt->format('Y-m-d H:i:s.v'))->toBe('2017-08-05 00:41:02.669')
            ->and(json_encode([$feedback, $complaint], JSON_THROW_ON_ERROR).$open)->not->toContain('example.com')
            ->not->toContain('192.0.2')
            ->not->toContain('123.456')
            ->not->toContain('Mozilla');
    });

    it('carries each event through to its effect on the email and the address', function (string $fixture, array $changes, Closure $expect): void {
        $subscriber = Subscriber::factory()->create();
        $message = CampaignMessage::factory()->for($subscriber)->create(['driver' => 'ses', 'status' => MessageStatus::Sent, 'sent_at' => now()->subHour()]);
        $body = sesEvent($fixture, ['detail' => ['mail' => ['tags' => ['campaign-message' => [$message->uuid]]], ...$changes]]);

        expect($this->postJson(route('campaigns.webhooks', 'ses'), $body, [SesDriver::WEBHOOK_HEADER => 'webhook-secret'])->status())->toBe(200);

        app()->call([new ProcessFeedbackJob(ProviderEvent::query()->sole()->id), 'handle']);

        $expect($message->fresh(), $subscriber->fresh());
    })->with([
        'delivered' => ['delivery', [], function (CampaignMessage $message): void {
            expect($message->status)->toBe(MessageStatus::Delivered);
        }],
        'a hard bounce blocks the address' => ['bounce', [], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message)->status->toBe(MessageStatus::Failed)->failure_reason->toBe('hard_bounce')->failure_code->toBe(550)
                ->and($subscriber->blocked_reason)->toBe(BlockReason::HardBounce);
        }],
        'a suppressed bounce blocks it too' => ['bounce', ['bounce' => ['bounceSubType' => 'OnAccountSuppressionList']], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message)->status->toBe(MessageStatus::Failed)->failure_reason->toBe('suppressed_bounce')
                ->and($subscriber->blocked_reason)->toBe(BlockReason::HardBounce);
        }],
        'a soft bounce counts one' => ['bounce', ['bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MailboxFull']], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message->failure_reason)->toBe('soft_bounce')
                ->and($subscriber->soft_bounces)->toBe(1)
                ->and($subscriber->blocked_at)->toBeNull();
        }],
        'a spam report blocks and opts out' => ['complaint', [], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message->complained_at)->not->toBeNull()
                ->and($subscriber->blocked_reason)->toBe(BlockReason::Complaint)
                ->and($subscriber->opted_out_at)->not->toBeNull();
        }],
        'a suppressed complaint blocks, but counts as no complaint' => ['complaint', ['complaint' => ['complaintSubType' => 'OnAccountSuppressionList']], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message->complained_at)->toBeNull()
                ->and($message->failure_reason)->toBe('suppressed_complaint')
                ->and($subscriber->blocked_reason)->toBe(BlockReason::Complaint);
        }],
        'a virus fails the email, nothing against the address' => ['reject', [], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message)->status->toBe(MessageStatus::Failed)->failure_reason->toBe('other')
                ->and($subscriber->blocked_at)->toBeNull();
        }],
        'a delay changes nothing yet' => ['delivery-delay', [], function (CampaignMessage $message, Subscriber $subscriber): void {
            expect($message->status)->toBe(MessageStatus::Sent)
                ->and($subscriber->soft_bounces)->toBe(0);
        }],
    ]);

    it('marks sent an email whose send ended unclear when SES says it sent it', function (): void {
        $message = CampaignMessage::factory()->create(['driver' => 'ses', 'status' => MessageStatus::Unknown, 'sending_at' => now()->subMinutes(20)]);

        $this->postJson(route('campaigns.webhooks', 'ses'), sesEvent('send', ['detail' => ['mail' => ['tags' => ['campaign-message' => [$message->uuid]]]]]), [SesDriver::WEBHOOK_HEADER => 'webhook-secret']);
        app()->call([new ProcessFeedbackJob(ProviderEvent::query()->sole()->id), 'handle']);

        expect($message->fresh())->status->toBe(MessageStatus::Sent)
            ->provider_message_id->toBe('EXAMPLE7c191be45-e9aedb9a-02f9-4d12-a87d-dd0099a07f8a-000000');
    });
});

describe('suppressions', function (): void {
    it('reads the account\'s whole list a page at a time, whatever the domain', function (): void {
        $handler = new MockHandler([
            new Result(['SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'gone@example.org', 'Reason' => 'BOUNCE', 'LastUpdateTime' => new DateTimeImmutable('2026-02-01T10:00:00Z')],
                ['EmailAddress' => 'angry@example.org', 'Reason' => 'COMPLAINT', 'LastUpdateTime' => new DateTimeImmutable('2026-02-02T10:00:00Z')],
            ], 'NextToken' => 'page-2']),
            new Result(['SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'odd@example.org', 'Reason' => 'SOMETHING_NEW'],
                ['EmailAddress' => 'later@example.org', 'Reason' => 'BOUNCE'],
            ]]),
        ]);
        sesMailer($handler);

        $read = iterator_to_array(app(SesDriver::class)->suppressions('news.example.com'), false);

        expect(array_map(fn (Suppression $suppression): array => [$suppression->email, $suppression->type, $suppression->since?->toDateString()], $read))->toBe([
            ['gone@example.org', SuppressionType::Bounce, '2026-02-01'],
            ['angry@example.org', SuppressionType::Complaint, '2026-02-02'],
            ['later@example.org', SuppressionType::Bounce, null],
        ])
            ->and($handler->getLastCommand()->getName())->toBe('ListSuppressedDestinations')
            ->and($handler->getLastCommand()['NextToken'])->toBe('page-2')
            ->and($handler->getLastCommand()['PageSize'])->toBe(1000);
    });

    it('looks one address up, an empty answer when SES doesn\'t hold it', function (): void {
        $handler = new MockHandler([
            new Result(['SuppressedDestination' => ['EmailAddress' => 'gone@example.org', 'Reason' => 'BOUNCE', 'LastUpdateTime' => new DateTimeImmutable('2026-02-01T10:00:00Z'), 'Attributes' => ['MessageId' => 'x']]]),
            sesError('NotFoundException', 404),
        ]);
        sesMailer($handler);

        $held = app(SesDriver::class)->suppressionsFor('news.example.com', 'gone@example.org');
        $command = $handler->getLastCommand();
        $free = app(SesDriver::class)->suppressionsFor('news.example.com', 'reader@example.org');

        expect($held)->toHaveCount(1)
            ->and($held[0]->type)->toBe(SuppressionType::Bounce)
            ->and($command->getName())->toBe('GetSuppressedDestination')
            ->and($command['EmailAddress'])->toBe('gone@example.org')
            ->and($free)->toBe([]);
    });

    it('fails without SES\'s words or the address when the list can\'t be read', function (Closure $error, string $says): void {
        sesMailer(new MockHandler([$error]));

        try {
            app(SesDriver::class)->suppressionsFor('news.example.com', 'reader@example.org');
        } catch (CampaignsException $exception) {
            expect($exception->getMessage())->toContain($says)
                ->not->toContain('reader@example.org')
                ->and($exception->getPrevious())->toBeNull();

            return;
        }

        throw new RuntimeException('Nothing was refused.');
    })->with([
        'denied' => [sesError('AccessDeniedException', 403), 'Amazon SES answered 403 (AccessDeniedException) when the package read its suppression list. The campaigns mailer\'s credentials need ses:ListSuppressedDestinations'],
        'unreachable' => [sesError(null, null, connection: true), 'Amazon SES couldn\'t be reached when the package read its suppression list.'],
        'no credentials' => [fn (): CredentialsException => new CredentialsException('No credentials for reader@example.org.'), 'The AWS SDK found no credentials'],
    ]);

    it('tries a throttled read again a second later, three times in all', function (): void {
        Sleep::fake();
        sesMailer(new MockHandler([sesError('TooManyRequestsException', 429), sesError('TooManyRequestsException', 429), new Result([])]));

        $held = app(SesDriver::class)->suppressionsFor('news.example.com', 'reader@example.org');

        sesMailer(new MockHandler([sesError('TooManyRequestsException', 429), sesError('TooManyRequestsException', 429), sesError('TooManyRequestsException', 429)]));

        expect($held)->toBe([])
            ->and(fn () => app(SesDriver::class)->suppressionsFor('news.example.com', 'reader@example.org'))
            ->toThrow(CampaignsException::class, 'Amazon SES answered 429 (TooManyRequestsException)');

        Sleep::assertSleptTimes(4);
    });

    it('can\'t read SES through a mailer that isn\'t on it', function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'array']);

        expect(fn () => iterator_to_array(app(SesDriver::class)->suppressions('news.example.com')))
            ->toThrow(CampaignsException::class, 'campaigns.mail.mailer must name a mailer on the ses-v2 transport');
    });
});

describe('daily counts', function (): void {
    beforeEach(function (): void {
        $this->travelTo(Carbon::parse('2026-02-03 14:30:00', 'UTC'));
    });

    it('says the counts need VDM while it is off, which the overview shows as a note', function (): void {
        sesMailer(new MockHandler);
        config()->set('campaigns.driver', 'ses');
        config()->set('campaigns.mail.domain', 'news.example.com');

        expect(fn () => app(SesDriver::class)->domainMetrics('news.example.com', now()->subDay(), now()))
            ->toThrow(CampaignsException::class, 'Virtual Deliverability Manager')
            ->and(DomainSummary::for('news.example.com'))
            ->summary->provider->toBeNull()
            ->notes->toBe(['The provider\'s numbers for news.example.com couldn\'t be read: Amazon SES counts a domain\'s mail by day only with Virtual Deliverability Manager, a paid add-on: turn it on in SES, then set campaigns.drivers.ses.vdm (CAMPAIGNS_SES_VDM) to true.']);
    });

    it('reads the domain identity\'s sends, hard bounces and complaints by UTC day, with VDM on', function (): void {
        config()->set('campaigns.drivers.ses.vdm', true);
        $handler = new MockHandler([new Result(['Results' => [
            ['Id' => 'send', 'Timestamps' => [new DateTimeImmutable('2026-02-01T00:00:00Z'), new DateTimeImmutable('2026-02-02T00:00:00Z'), new DateTimeImmutable('2026-01-20T00:00:00Z')], 'Values' => [120, 340, 999]],
            ['Id' => 'permanent_bounce', 'Timestamps' => [new DateTimeImmutable('2026-02-02T00:00:00Z')], 'Values' => [3]],
            ['Id' => 'complaint', 'Timestamps' => [], 'Values' => []],
        ], 'Errors' => []])]);
        sesMailer($handler);

        $metrics = app(SesDriver::class)->domainMetrics('news.example.com', now()->subDays(2), now());
        $queries = $handler->getLastCommand()['Queries'];

        expect($metrics->days)->toBe([
            '2026-02-01' => ['sent' => 120, 'forwarded' => null, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => null],
            '2026-02-02' => ['sent' => 340, 'forwarded' => null, 'hard_bounces' => 3, 'complaints' => 0, 'unsubscribes' => null],
            '2026-02-03' => ['sent' => 0, 'forwarded' => null, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => null],
        ])
            ->and($metrics->totals())->toBe(['sent' => 460, 'forwarded' => null, 'hard_bounces' => 3, 'complaints' => 0, 'unsubscribes' => null])
            ->and(array_column($queries, 'Metric'))->toBe(['SEND', 'PERMANENT_BOUNCE', 'COMPLAINT'])
            ->and($queries[0]['Namespace'])->toBe('VDM')
            ->and($queries[0]['Dimensions'])->toBe(['EMAIL_IDENTITY' => 'news.example.com'])
            ->and($queries[0]['StartDate']->format(DATE_ATOM))->toBe('2026-02-01T00:00:00+00:00')
            ->and($queries[0]['EndDate']->format(DATE_ATOM))->toBe('2026-02-03T14:30:00+00:00');
    });

    it('fails, naming the codes, when SES refuses a query', function (): void {
        config()->set('campaigns.drivers.ses.vdm', true);
        sesMailer(new MockHandler([new Result(['Results' => [], 'Errors' => [['Id' => 'send', 'Code' => 'ACCESS_DENIED', 'Message' => 'Denied for reader@example.org']]])]));

        expect(fn () => app(SesDriver::class)->domainMetrics('news.example.com', now()->subDay(), now()))
            ->toThrow(CampaignsException::class, 'Amazon SES couldn\'t give its daily counts (ACCESS_DENIED). The campaigns mailer\'s credentials need ses:BatchGetMetricData.');
    });
});
