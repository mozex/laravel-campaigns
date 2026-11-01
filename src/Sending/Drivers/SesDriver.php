<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\SesV2\SesV2Client;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ReadsMetrics;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Mail\CampaignEmail;
use Mozex\Campaigns\Sending\DomainMetrics;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\Sender;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Throwable;

/**
 * Sends through Amazon SES's v2 API by way of the app's campaigns mailer
 * (campaigns.mail.mailer), which must use Laravel's ses-v2 transport with the
 * AWS SDK's retries off ('retries' => 0, on the mailer or services.ses): the
 * SDK retries a request whose answer was lost, which sends the email twice.
 * The log and array mailers are allowed outside production. The message's
 * uuid travels as an SES message tag (Laravel turns the mailable's metadata
 * into tags); the tag header Laravel adds is dropped, since SES reads only
 * the metadata. The configuration set is the mailer's own option
 * (ConfigurationSetName). SES's own open and click tracking is turned off
 * on every email (SES's per-email override, which needs aws-sdk-php
 * 3.393.3), and any open or click event it still reports is dropped: the
 * package tracks both itself.
 *
 * SES posts what happened to an email through an Amazon EventBridge API
 * destination to /campaigns/webhooks/ses, each request carrying the
 * campaigns.drivers.ses.webhook_secret in the X-Campaigns-Webhook-Key
 * header; the event's id is its key, so a retry is stored once. SES keeps no
 * logs a driver can read, so an event a webhook missed is lost, and an email
 * whose send ended unclear stays unknown. Its suppression list is the
 * account's in the region, whatever the domain; its daily counts need
 * Virtual Deliverability Manager (campaigns.drivers.ses.vdm). Reads go
 * through the campaigns mailer's own SES client.
 *
 * A failure is read from the AWS error code and the HTTP status, never the
 * service's words, which may quote an address.
 */
class SesDriver implements CampaignDriver, ReadsMetrics, ReadsSuppressions, ReceivesWebhooks
{
    use UsesCampaignsMailer;

    /**
     * The HTTP limits an ses-v2 mailer gets when the app gave it none
     * (DriverManager::boundMailer()), so a stuck request ends before the job
     * sending it.
     */
    public const HTTP_LIMITS = ['connect_timeout' => 10, 'timeout' => 30];

    /**
     * The header an EventBridge connection sends the webhook secret in.
     */
    public const WEBHOOK_HEADER = 'X-Campaigns-Webhook-Key';

    /**
     * How far an event's time may be from now, in seconds: EventBridge
     * retries a delivery for up to 24 hours.
     */
    public const WEBHOOK_MAX_AGE = 86400;

    /**
     * SES took nothing and may take it later: throttled (the daily quota
     * included), a request it didn't receive whole, or its servers briefly
     * failing. A 504 isn't here: a gateway that timed out may have handed
     * the message on.
     */
    protected const NOT_ACCEPTED = [
        'codes' => ['TooManyRequestsException', 'ThrottlingException', 'Throttling', 'RequestTimeoutException'],
        'statuses' => [408, 429, 500, 502, 503],
    ];

    /**
     * SES refuses the account or the sender as configured (suspended,
     * paused, an unverified MAIL FROM domain or From identity, a
     * configuration set or identity it doesn't have, credentials it
     * refuses), so every email would fail alike. MessageRejected is here:
     * SES gives it for an unverified From address as for content it refuses,
     * either of which fails every email of a campaign, so they wait rather
     * than fail for good. LimitExceededException is a standing limit of the
     * account, which waiting doesn't lift.
     */
    protected const DOMAIN_REFUSED = [
        'codes' => ['AccountSuspendedException', 'SendingPausedException', 'MailFromDomainNotVerifiedException', 'NotFoundException', 'MessageRejected', 'LimitExceededException'],
        'statuses' => [401, 403, 404],
    ];

    /**
     * SES refuses this email for good: a field it can't take.
     */
    protected const REJECTED = ['BadRequestException'];

    /**
     * SES's event types the package acts on. Opens and clicks are dropped:
     * the package tracks both itself. A rendering failure happens only to
     * SES's own templates, which the package never sends.
     */
    protected const EVENT_TYPES = [
        'Send' => FeedbackType::Accepted,
        'Subscription' => FeedbackType::Accepted,
        'Delivery' => FeedbackType::Delivered,
        'Bounce' => FeedbackType::Failed,
        'Reject' => FeedbackType::Failed,
        'DeliveryDelay' => FeedbackType::Failed,
        'Complaint' => FeedbackType::Complained,
    ];

    /**
     * Bounce and complaint subtypes that say SES sent nothing, since the
     * account's or a tenant's suppression list holds the address, which SES
     * doesn't count toward the account's bounce rate. The global list's
     * Suppressed isn't here: SES counts it, so it is a hard bounce.
     */
    protected const SUPPRESSED = ['OnAccountSuppressionList', 'OnTenantSuppressionList'];

    /**
     * Bounce subtypes that say nothing against the address: the email's own
     * size or content, a time limit the sender set, or SES's own validation
     * guessing rather than a mailbox refusing.
     */
    protected const NOT_THE_ADDRESS = ['MessageTooLarge', 'ContentRejected', 'AttachmentRejected', 'CustomTimeoutExceeded', 'EmailValidationSuppressed'];

    /**
     * Delays that are nothing the address did: the receiving provider
     * refusing the sender, or SES's own trouble.
     */
    protected const SENDER_DELAYS = ['SpamDetected', 'IPFailure', 'InternalFailure', 'SendingDeferral', 'BYOIPHostNameLookupUnavailable'];

    /**
     * Feedback reports that are no person's spam report: a correction, and
     * an authentication failure report.
     */
    protected const NOT_COMPLAINTS = ['not-spam', 'auth-failure'];

    /**
     * How often a read SES throttled is tried again, a second apart: SES
     * takes one request a second for everything but sending.
     */
    protected const READ_TRIES = 3;

    /**
     * VDM's metrics a domain's day is read from, by the name DomainMetrics
     * gives each.
     */
    protected const METRICS = [
        'SEND' => 'sent',
        'PERMANENT_BOUNCE' => 'hard_bounces',
        'COMPLAINT' => 'complaints',
    ];

    /**
     * The suppression list's reasons.
     */
    protected const SUPPRESSION_REASONS = [
        'BOUNCE' => SuppressionType::Bounce,
        'COMPLAINT' => SuppressionType::Complaint,
    ];

    public function send(OutgoingMessage $message): SendReceipt
    {
        $mailer = $this->mailer();

        $email = (new CampaignEmail($message))->withSymfonyMessage(function (Email $email): void {
            $headers = $email->getHeaders();
            $names = [];

            foreach ($headers->all() as $header) {
                if ($header instanceof TagHeader) {
                    $names[] = $header->getName();
                }
            }

            foreach (array_unique($names) as $name) {
                $headers->remove($name);
            }
        });

        try {
            $sent = $mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw $this->failure($exception);
        } catch (CredentialsException) {
            // Thrown before the request is signed, so nothing went.
            throw new SendFailed(SendFailure::DomainRefused, 'The AWS SDK found no credentials it could use for the campaigns mailer, so nothing can go until they are set.');
        } catch (Throwable $exception) {
            // Anything else, such as an app's MessageSent listener failing
            // after SES took the message: reported, since it is a bug
            // somewhere, and unclear, since the message may have gone.
            report($exception);

            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        }

        $original = $sent?->getSymfonySentMessage()->getOriginalMessage();
        // SES's own id, which its events carry, is the header Laravel's
        // transport adds; the log and array mailers have only the email's.
        $sesId = $original instanceof Message ? $original->getHeaders()->get('X-SES-Message-ID')?->getBodyAsString() : null;
        $id = trim((string) ($sesId ?? $sent?->getMessageId()), "<> \t");

        if ($id === '') {
            // A MessageSending listener can stop a message, and a faked
            // mailer records it instead: either way there is no receipt.
            throw new SendFailed(SendFailure::Unclear, 'The mailer gave no message id, so whether the message went is unknown.');
        }

        return new SendReceipt($id);
    }

    /**
     * The webhook secret in the request's header, compared in constant time,
     * and an event dated within WEBHOOK_MAX_AGE of now either way. The secret
     * is the only proof (an EventBridge API destination signs nothing), so
     * the event's id is all that stops one being stored twice.
     *
     * @throws CampaignsException when no webhook secret is configured, so the
     *                            request answers 500 and EventBridge retries
     *                            while the config is fixed
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = config('campaigns.drivers.ses.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new CampaignsException('An Amazon SES event arrived, but no webhook secret is configured (campaigns.drivers.ses.webhook_secret, CAMPAIGNS_SES_WEBHOOK_SECRET), so it can\'t be verified.');
        }

        $key = $request->header(static::WEBHOOK_HEADER);

        if (! is_string($key) || ! hash_equals($secret, $key)) {
            return false;
        }

        $time = $this->time($request->json('time'));

        return $time !== null && abs(now()->getTimestamp() - $time->getTimestamp()) <= static::WEBHOOK_MAX_AGE;
    }

    public function parseWebhook(Request $request): array
    {
        $id = $request->json('id');
        $detail = $request->json('detail');

        if (! is_string($id) || ! is_array($detail)) {
            return [];
        }

        $feedback = $this->feedback($id, $detail, $this->time($request->json('time')));

        return $feedback === null ? [] : [$feedback];
    }

    /**
     * The account's suppression list in the campaigns mailer's region, read
     * a thousand at a time to its end; SES keeps one list for the whole
     * account, so the domain is ignored.
     */
    public function suppressions(string $domain): iterable
    {
        $client = $this->client();
        $token = null;
        $seen = [];

        while (true) {
            $result = $this->read(fn (): array => $client->listSuppressedDestinations(array_filter(['PageSize' => 1000, 'NextToken' => $token]))->toArray(), 'its suppression list');
            $summaries = $result['SuppressedDestinationSummaries'] ?? null;

            foreach (is_array($summaries) ? $summaries : [] as $summary) {
                $suppression = is_array($summary) ? $this->suppression($summary) : null;

                if ($suppression !== null) {
                    yield $suppression;
                }
            }

            $token = $result['NextToken'] ?? null;

            if (! is_string($token) || $token === '' || isset($seen[$token])) {
                return;
            }

            $seen[$token] = true;
        }
    }

    /**
     * SES compares addresses on its list as they were added, so one added
     * in other letter cases isn't found by the package's lower-cased one.
     */
    public function suppressionsFor(string $domain, string $email): array
    {
        $client = $this->client();

        $result = $this->read(fn (): array => $client->getSuppressedDestination(['EmailAddress' => $email])->toArray(), 'its suppression list', notFound: true);
        $destination = $result['SuppressedDestination'] ?? null;
        $suppression = is_array($destination) ? $this->suppression($destination) : null;

        return $suppression === null ? [] : [$suppression];
    }

    /**
     * Read through VDM's BatchGetMetricData for the domain's identity, its
     * points summed by UTC day; a day it leaves out counts 0. SES counts no
     * forwarded mail and no unsubscribes, so those are null.
     *
     * @throws CampaignsException when VDM is off (campaigns.drivers.ses.vdm)
     */
    public function domainMetrics(string $domain, CarbonInterface $from, CarbonInterface $to): DomainMetrics
    {
        if (! filter_var(config('campaigns.drivers.ses.vdm'), FILTER_VALIDATE_BOOL)) {
            throw new CampaignsException('Amazon SES counts a domain\'s mail by day only with Virtual Deliverability Manager, a paid add-on: turn it on in SES, then set campaigns.drivers.ses.vdm (CAMPAIGNS_SES_VDM) to true.');
        }

        $client = $this->client();
        $first = Carbon::instance($from)->utc()->startOfDay();
        $last = Carbon::instance($to)->utc()->startOfDay();
        $queries = [];

        foreach (array_keys(static::METRICS) as $metric) {
            $queries[] = [
                'Id' => strtolower($metric),
                'Namespace' => 'VDM',
                'Metric' => $metric,
                'Dimensions' => ['EMAIL_IDENTITY' => $domain],
                'StartDate' => $first->toDateTimeImmutable(),
                'EndDate' => Carbon::instance($to)->utc()->toDateTimeImmutable(),
            ];
        }

        $result = $this->read(fn (): array => $client->batchGetMetricData(['Queries' => $queries])->toArray(), 'its daily counts');
        $errors = $result['Errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $codes = implode(', ', array_unique(array_map(fn (mixed $error): string => is_array($error) ? $this->code($error['Code'] ?? null) : 'unknown', $errors)));

            throw CampaignsException::providerUnreadable("Amazon SES couldn't give its daily counts ({$codes}).".(str_contains($codes, 'ACCESS_DENIED') ? ' The campaigns mailer\'s credentials need ses:BatchGetMetricData.' : ''));
        }

        $days = [];

        for ($day = $first->copy(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            $days[$day->toDateString()] = ['sent' => 0, 'forwarded' => null, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => null];
        }

        $results = $result['Results'] ?? null;

        foreach (is_array($results) ? $results : [] as $series) {
            $count = is_array($series) ? (static::METRICS[strtoupper((string) ($series['Id'] ?? ''))] ?? null) : null;

            if ($count === null || ! is_array($series['Timestamps'] ?? null) || ! is_array($series['Values'] ?? null)) {
                continue;
            }

            foreach (array_values($series['Timestamps']) as $index => $timestamp) {
                $value = $series['Values'][$index] ?? null;
                $date = $timestamp instanceof DateTimeInterface ? Carbon::instance($timestamp)->utc()->toDateString() : null;

                if ($date !== null && isset($days[$date]) && is_numeric($value)) {
                    $days[$date][$count] += max(0, (int) $value);
                }
            }
        }

        /** @var array<string, array{sent: int, forwarded: null, hard_bounces: int, complaints: int, unsubscribes: null}> $days */
        return new DomainMetrics($days);
    }

    /**
     * The campaigns mailer, checked before anything is sent: one that isn't
     * named, can't be built, doesn't send through SES's v2 API, delivers
     * nothing in production, lets the SDK retry, or has an SDK too old to
     * turn SES's tracking off refuses for the whole domain until its config
     * is fixed.
     *
     * SES's own open and click tracking is turned off on the transport for
     * every email it sends: SES adds its pixel and rewrites links when the
     * account's or the configuration set's VDM engagement tracking is on, as
     * well as for an event destination with opens and clicks, and the
     * package tracks both itself.
     *
     * @throws SendFailed
     */
    protected function mailer(): MailerContract
    {
        $mailer = $this->campaignsMailer(SesV2Transport::class, 'Amazon SES\'s v2 API', "the ses-v2 transport with 'retries' => 0");
        $transport = $mailer instanceof Mailer ? $mailer->getSymfonyTransport() : null;

        if (! $transport instanceof SesV2Transport) {
            return $mailer;
        }

        $name = trim(Sender::mailer());
        // Laravel merges the mailer's settings over services.ses one key
        // deep; without either, the SDK retries by default.
        $settings = [...(array) config('services.ses', []), ...(array) config('mail.mailers.'.$name, [])];

        if (($settings['retries'] ?? null) !== 0) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" lets the AWS SDK retry a send, which sends an email twice when SES's answer is lost: give it 'retries' => 0.");
        }

        // An older SDK drops a field it doesn't know without a word.
        if (! $transport->ses()->getApi()->getOperation('SendEmail')->getInput()->hasMember('ConfigurationOverrides')) {
            throw new SendFailed(SendFailure::DomainRefused, 'The installed AWS SDK for PHP can\'t turn Amazon SES\'s own open and click tracking off for an email: update aws/aws-sdk-php to 3.393.3 or later.');
        }

        $transport->setOptions(array_replace_recursive($transport->getOptions(), [
            'ConfigurationOverrides' => ['Tracking' => ['OpenTrackingEnabled' => 'DISABLED', 'ClickTrackingEnabled' => 'DISABLED']],
        ]));

        return $mailer;
    }

    /**
     * What a failure while sending means, by the AWS error code and the HTTP
     * status of the SDK's exception, which Laravel's transport wraps. A
     * connection error is unclear whatever kind it is: the SDK's flag doesn't
     * say whether the request went, and a lost answer may have.
     */
    protected function failure(TransportExceptionInterface $exception): SendFailed
    {
        $aws = $exception->getPrevious();

        if (! $aws instanceof AwsException) {
            return new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        }

        $status = $aws->getStatusCode();

        if ($aws->isConnectionError() || ! is_int($status)) {
            return new SendFailed(SendFailure::Unclear, 'The connection to Amazon SES failed before it answered, so it may have the message.');
        }

        $code = $this->code($aws->getAwsErrorCode());

        $failure = match (true) {
            in_array($code, static::NOT_ACCEPTED['codes'], true), in_array($status, static::NOT_ACCEPTED['statuses'], true) => SendFailure::NotAccepted,
            in_array($code, static::DOMAIN_REFUSED['codes'], true), in_array($status, static::DOMAIN_REFUSED['statuses'], true) => SendFailure::DomainRefused,
            in_array($code, static::REJECTED, true) => SendFailure::Rejected,
            default => SendFailure::Unclear,
        };

        return new SendFailed($failure, "Amazon SES answered {$status} ({$code}).", $status);
    }

    /**
     * One SES event in the package's terms, or null for an event the package
     * doesn't act on or one too broken to place. The details kept are those
     * an effect or a report reads; the recipients' addresses, the headers,
     * the source and every IP stay out, and a mail server's diagnostic is
     * scrubbed of addresses.
     *
     * @param  array<mixed>  $detail
     */
    protected function feedback(string $id, array $detail, ?Carbon $eventTime): ?Feedback
    {
        $name = $detail['eventType'] ?? null;
        $type = is_string($name) ? (static::EVENT_TYPES[$name] ?? null) : null;
        $mail = is_array($detail['mail'] ?? null) ? $detail['mail'] : [];
        // Each type's own object is named as the type is, in camel case
        // (bounce, deliveryDelay).
        $part = is_string($name) ? ($detail[lcfirst($name)] ?? null) : null;
        $part = is_array($part) ? $part : [];
        $occurredAt = $this->time($part['timestamp'] ?? null) ?? $this->time($mail['timestamp'] ?? null) ?? $eventTime;

        if ($type === null || trim($id) === '' || strlen($id) > 255 || $occurredAt === null) {
            return null;
        }

        $tags = is_array($mail['tags'] ?? null) ? $mail['tags'] : [];
        $outcome = match ($name) {
            'Bounce' => $this->bounce($part),
            'Complaint' => $this->complaint($part),
            'Reject' => ['type' => FeedbackType::Failed, 'severity' => 'permanent', 'reason' => $this->string($part['reason'] ?? null), 'failure' => DeliveryFailure::Other, 'recipient' => [], 'details' => []],
            'DeliveryDelay' => $this->delay($part),
            default => ['type' => $type, 'severity' => null, 'reason' => null, 'failure' => null, 'recipient' => [], 'details' => []],
        };

        $recipient = $outcome['recipient'];
        $diagnostic = is_string($recipient['diagnosticCode'] ?? null) ? $recipient['diagnosticCode'] : null;

        return new Feedback(
            providerEventId: trim($id),
            type: $outcome['type'],
            occurredAt: $occurredAt,
            messageUuid: $this->tag($tags, OutgoingMessage::MESSAGE_KEY),
            testSendUuid: $this->tag($tags, OutgoingMessage::TEST_KEY),
            providerMessageId: $this->string($mail['messageId'] ?? null),
            severity: $outcome['severity'],
            reason: $outcome['reason'],
            failure: $outcome['failure'],
            code: $diagnostic !== null && preg_match('/^\s*smtp\s*;\s*([2-5]\d\d)\b/i', $diagnostic, $matches) === 1 ? (int) $matches[1] : null,
            payload: array_filter([
                ...$outcome['details'],
                'status' => $this->string($recipient['status'] ?? null, 32),
                'action' => $this->string($recipient['action'] ?? null, 32),
                'diagnostic-code' => $diagnostic === null ? null : SendFailed::scrub($diagnostic, 255),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * A bounce, which SES reports only once it has stopped trying: a
     * permanent one is a hard bounce, unless a suppression list stopped the
     * send; a transient one a soft bounce, unless its subtype says nothing
     * against the address; an undetermined one counts nothing.
     *
     * @param  array<mixed>  $bounce
     * @return array{type: FeedbackType, severity: string, reason: ?string, failure: DeliveryFailure, recipient: array<mixed>, details: array<string, ?string>}
     */
    protected function bounce(array $bounce): array
    {
        $type = $this->string($bounce['bounceType'] ?? null);
        $subtype = $this->string($bounce['bounceSubType'] ?? null);

        $failure = match (true) {
            in_array($subtype, static::SUPPRESSED, true) => DeliveryFailure::SuppressedBounce,
            in_array($subtype, static::NOT_THE_ADDRESS, true) => DeliveryFailure::Other,
            $type === 'Permanent' => DeliveryFailure::HardBounce,
            $type === 'Transient' => DeliveryFailure::SoftBounce,
            default => DeliveryFailure::Other,
        };

        return [
            'type' => FeedbackType::Failed,
            'severity' => 'permanent',
            'reason' => $subtype,
            'failure' => $failure,
            'recipient' => $this->firstRecipient($bounce['bouncedRecipients'] ?? null),
            'details' => ['bounce-type' => $type, 'bounce-subtype' => $subtype],
        ];
    }

    /**
     * A spam report, unless a suppression list stopped the send (which SES
     * reports as a complaint, though nobody complained about this email, so
     * the stop-loss never counts it), or the report says the email isn't
     * spam.
     *
     * @param  array<mixed>  $complaint
     * @return array{type: FeedbackType, severity: ?string, reason: ?string, failure: ?DeliveryFailure, recipient: array<mixed>, details: array<string, ?string>}
     */
    protected function complaint(array $complaint): array
    {
        $subtype = $this->string($complaint['complaintSubType'] ?? null);
        $feedbackType = $this->string($complaint['complaintFeedbackType'] ?? null, 32);
        $details = ['feedback-type' => $feedbackType, 'complaint-subtype' => $subtype];

        if (in_array($subtype, static::SUPPRESSED, true)) {
            return ['type' => FeedbackType::Failed, 'severity' => 'permanent', 'reason' => $subtype, 'failure' => DeliveryFailure::SuppressedComplaint, 'recipient' => [], 'details' => $details];
        }

        return [
            'type' => in_array($feedbackType, static::NOT_COMPLAINTS, true) ? FeedbackType::Accepted : FeedbackType::Complained,
            'severity' => null,
            'reason' => null,
            'failure' => null,
            'recipient' => [],
            'details' => $details,
        ];
    }

    /**
     * A delay while SES is still trying: nothing yet, and nothing against
     * the address when the receiving provider is refusing the sender.
     *
     * @param  array<mixed>  $delay
     * @return array{type: FeedbackType, severity: string, reason: ?string, failure: DeliveryFailure, recipient: array<mixed>, details: array<string, ?string>}
     */
    protected function delay(array $delay): array
    {
        $type = $this->string($delay['delayType'] ?? null);

        return [
            'type' => FeedbackType::Failed,
            'severity' => 'temporary',
            'reason' => $type,
            'failure' => in_array($type, static::SENDER_DELAYS, true) ? DeliveryFailure::SenderDeferred : DeliveryFailure::Deferred,
            'recipient' => $this->firstRecipient($delay['delayedRecipients'] ?? null),
            'details' => ['delay-type' => $type],
        ];
    }

    /**
     * The first recipient's details in an event: the package sends each
     * email to one address.
     *
     * @return array<mixed>
     */
    protected function firstRecipient(mixed $recipients): array
    {
        $first = is_array($recipients) ? reset($recipients) : null;

        return is_array($first) ? $first : [];
    }

    /**
     * A tag's first value: SES gives each tag as a list.
     *
     * @param  array<mixed>  $tags
     */
    protected function tag(array $tags, string $name): ?string
    {
        $values = $tags[$name] ?? null;

        return is_array($values) ? $this->string(reset($values)) : null;
    }

    /**
     * One entry of the suppression list.
     *
     * @param  array<mixed>  $entry
     */
    protected function suppression(array $entry): ?Suppression
    {
        $email = $this->string($entry['EmailAddress'] ?? null);
        $type = static::SUPPRESSION_REASONS[$entry['Reason'] ?? ''] ?? null;

        if ($email === null || $type === null) {
            return null;
        }

        $since = $entry['LastUpdateTime'] ?? null;

        return new Suppression($email, $type, $since instanceof DateTimeInterface ? Carbon::instance($since) : null);
    }

    /**
     * The SES client of the campaigns mailer, which reads with the same
     * credentials and region it sends with.
     *
     * @throws CampaignsException
     */
    protected function client(): SesV2Client
    {
        $name = trim(Sender::mailer());

        try {
            $transport = $name === '' ? null : Mail::mailer($name)->getSymfonyTransport();
        } catch (Throwable) {
            $transport = null;
        }

        if (! $transport instanceof SesV2Transport) {
            throw CampaignsException::providerUnreadable('Amazon SES is read through the campaigns mailer\'s own client, so campaigns.mail.mailer must name a mailer on the ses-v2 transport.');
        }

        return $transport->ses();
    }

    /**
     * One request to SES's API, tried again a second later when SES
     * throttled it (READ_TRIES in all). Nothing that fails here is chained or
     * quotes the SDK's message: it holds the request's address, which for
     * one address's lookup holds the address.
     *
     * @param  callable(): array<mixed>  $request
     * @param  bool  $notFound  whether a 404 means "not there" (an empty answer)
     * @return array<mixed>
     *
     * @throws CampaignsException
     */
    protected function read(callable $request, string $what, bool $notFound = false, int $try = 1): array
    {
        try {
            return $request();
        } catch (AwsException $exception) {
            $status = $exception->getStatusCode();

            if ($status === 429 && $try < static::READ_TRIES) {
                Sleep::for(1)->second();

                return $this->read($request, $what, $notFound, $try + 1);
            }

            if ($notFound && $status === 404) {
                return [];
            }

            $code = $this->code($exception->getAwsErrorCode());
            $fix = in_array($status, [401, 403], true) ? ' The campaigns mailer\'s credentials need ses:ListSuppressedDestinations, ses:GetSuppressedDestination and, with VDM, ses:BatchGetMetricData.' : '';

            throw CampaignsException::providerUnreadable(($exception->isConnectionError() || ! is_int($status) ? 'Amazon SES couldn\'t be reached' : "Amazon SES answered {$status} ({$code})")." when the package read {$what}.{$fix}");
        } catch (CredentialsException) {
            throw CampaignsException::providerUnreadable("The AWS SDK found no credentials it could use for the campaigns mailer when the package read {$what}.");
        } catch (Throwable $exception) {
            throw CampaignsException::providerUnreadable("Reading {$what} from Amazon SES failed (".class_basename($exception).').');
        }
    }

    /**
     * An AWS error code as it may be shown: a name of letters, digits and
     * dots, never free text.
     */
    protected function code(mixed $code): string
    {
        return is_string($code) && preg_match('/\A[A-Za-z0-9_.]{1,64}\z/', $code) === 1 ? $code : 'unknown';
    }

    /**
     * A non-empty string from an event's field, cut to fit, or null.
     */
    protected function string(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strimwidth(trim($value), 0, $max);
    }

    /**
     * An ISO 8601 time, as every SES and EventBridge time is.
     */
    protected function time(mixed $value): ?Carbon
    {
        // A blank would parse as now.
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
