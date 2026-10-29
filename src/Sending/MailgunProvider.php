<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Mail\CampaignEmail;
use Mozex\Campaigns\Support\Sender;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface as HttpClientTransportException;
use Throwable;

/**
 * Sends through Mailgun's HTTP API by way of the app's campaigns mailer
 * (campaigns.mail.mailer), which must use Mailgun's "api" scheme: that
 * transport turns the message's tag, metadata and o: options into the API's
 * own fields, where the MIME and SMTP ones would put them in the email as
 * headers. The log and array mailers are allowed too, for local runs and
 * tests, since they deliver nothing.
 */
class MailgunProvider implements CampaignProvider
{
    /**
     * Mailgun took nothing and may take it later: rate limited, or its
     * servers briefly failing. A 504 isn't here: a gateway that timed out
     * may have handed the message on.
     */
    protected const NOT_ACCEPTED = [429, 500, 502, 503];

    /**
     * Mailgun refuses the domain or the account (a wrong key, a disabled or
     * unknown domain), so every message from the domain would fail alike.
     */
    protected const DOMAIN_REFUSED = [401, 403, 404];

    /**
     * How far a webhook's signed timestamp may be from now, in seconds.
     */
    public const WEBHOOK_MAX_AGE = 86400;

    /**
     * Mailgun's event names the package acts on. Both failure webhooks
     * (temporary and permanent) arrive as "failed", their severity telling
     * them apart.
     */
    protected const EVENT_TYPES = [
        'accepted' => FeedbackType::Accepted,
        'delivered' => FeedbackType::Delivered,
        'failed' => FeedbackType::Failed,
        'opened' => FeedbackType::Opened,
        'clicked' => FeedbackType::Clicked,
        'unsubscribed' => FeedbackType::Unsubscribed,
        'complained' => FeedbackType::Complained,
    ];

    public function send(OutgoingMessage $message): SendReceipt
    {
        $mailer = $this->mailer();

        $email = (new CampaignEmail($message))->withSymfonyMessage(function (Email $email) use ($message): void {
            // Both flags on every message, so the domain's own tracking
            // setting never decides for a campaign.
            $email->getHeaders()->addTextHeader('o:tracking-opens', $message->trackOpens ? 'yes' : 'no');
            $email->getHeaders()->addTextHeader('o:tracking-clicks', $message->trackClicks ? 'yes' : 'no');
        });

        try {
            $sent = $mailer->send($email);
        } catch (HttpTransportException $exception) {
            throw $this->failure($exception);
        } catch (TransportExceptionInterface $exception) {
            // Not chained, and its message not kept: a transport's error can
            // quote the recipient's address.
            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        } catch (Throwable $exception) {
            // Anything else, such as an app's MessageSent listener failing
            // after Mailgun took the message, or an answer the transport
            // couldn't read: reported, since it is a bug somewhere, and
            // unclear, since the message may have gone.
            report($exception);

            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        }

        $id = trim((string) $sent?->getMessageId(), "<> \t");

        if ($id === '') {
            // A MessageSending listener can stop a message, and a faked
            // mailer records it instead: either way there is no receipt.
            throw new SendFailed(SendFailure::Unclear, 'The mailer gave no message id, so whether the message went is unknown.');
        }

        // Mailgun's answer wraps the id in angle brackets and its events
        // don't, so it is stored bare.
        return new SendReceipt($id);
    }

    /**
     * The HMAC of the signature's timestamp and token, keyed with the
     * account's webhook signing key, against its signature (or, for a
     * subaccount's domain, the parent account's). The signature covers only
     * the timestamp and token, so the token is what stops a replay
     * (RecordFeedbackAction), and a timestamp more than WEBHOOK_MAX_AGE
     * seconds from now is refused: Mailgun retries for about 8 hours and
     * may not sign a retry again.
     *
     * @throws CampaignsException when no signing key is configured, so the
     *                            request answers 500 and Mailgun retries
     *                            while the config is fixed
     */
    public function verifyWebhook(Request $request): bool
    {
        $key = config('campaigns.mailgun.webhook_signing_key');

        if (! is_string($key) || $key === '') {
            throw CampaignsException::missingWebhookSigningKey();
        }

        $signature = $request->json('signature');

        if (! is_array($signature)) {
            return false;
        }

        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;

        if (is_int($timestamp)) {
            $timestamp = (string) $timestamp;
        }

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || strlen($timestamp) > 12
            || ! is_string($token) || $token === '' || strlen($token) > 255) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > static::WEBHOOK_MAX_AGE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.$token, $key);

        foreach (['signature', 'parent-signature'] as $field) {
            if (is_string($signature[$field] ?? null) && hash_equals($expected, strtolower($signature[$field]))) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $event = $request->json('event-data');
        $token = $request->json('signature.token');

        if (! is_array($event)) {
            return [];
        }

        $feedback = $this->feedback($event, is_string($token) ? $token : null);

        return $feedback === null ? [] : [$feedback];
    }

    public function eventsForMessage(string $messageUuid): array
    {
        throw $this->notYet();
    }

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        throw $this->notYet();
    }

    public function suppressions(string $domain): iterable
    {
        throw $this->notYet();
    }

    public function tracks(): bool
    {
        return true;
    }

    /**
     * The campaigns mailer, checked before anything is sent: a mailer that
     * isn't named or can't be built, one that would put Mailgun's options in
     * the email, or one that delivers nothing in production refuses for the
     * whole domain until its config is fixed.
     *
     * @throws SendFailed
     */
    protected function mailer(): MailerContract
    {
        $name = trim(Sender::mailer());

        // A blank name would resolve the app's default mailer, which is
        // usually the transactional domain's.
        if ($name === '') {
            throw new SendFailed(SendFailure::DomainRefused, 'No campaigns mailer is named (campaigns.mail.mailer, CAMPAIGNS_MAILER).');
        }

        try {
            $mailer = Mail::mailer($name);
        } catch (Throwable $exception) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" can't be used: ".SendFailed::scrub($exception->getMessage()));
        }

        // A faked mailer (Mail::fake()) has no transport to check.
        if (! $mailer instanceof Mailer) {
            return $mailer;
        }

        $transport = $mailer->getSymfonyTransport();
        $delivers = $transport instanceof MailgunApiTransport;

        if (! $delivers && ! $transport instanceof LogTransport && ! $transport instanceof ArrayTransport) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" must send through Mailgun's API: give it the mailgun transport with 'scheme' => 'api' (or the log or array mailer locally).");
        }

        // The log mailer would mark a whole campaign sent with nothing
        // delivered, and write every message, links' tokens and all, to the
        // app's log.
        if (! $delivers && app()->isProduction()) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" delivers nothing, which production never sends through: give it the mailgun transport with 'scheme' => 'api'.");
        }

        return $mailer;
    }

    protected function failure(HttpTransportException $exception): SendFailed
    {
        if ($exception->getPrevious() instanceof HttpClientTransportException) {
            return new SendFailed(SendFailure::Unclear, 'The connection to Mailgun failed before it answered, so it may have the message.');
        }

        try {
            $status = $exception->getResponse()->getStatusCode();
        } catch (Throwable) {
            return new SendFailed(SendFailure::Unclear, 'Mailgun\'s answer couldn\'t be read, so it may have the message.');
        }

        $failure = match (true) {
            in_array($status, static::NOT_ACCEPTED, true) => SendFailure::NotAccepted,
            in_array($status, static::DOMAIN_REFUSED, true) => SendFailure::DomainRefused,
            default => SendFailure::Unclear,
        };

        $reason = $this->reason($exception);

        return new SendFailed($failure, "Mailgun answered {$status}".($reason === '' ? '.' : ": {$reason}"), $status);
    }

    /**
     * Mailgun's own words for a refusal, without any address in them.
     */
    protected function reason(HttpTransportException $exception): string
    {
        try {
            $body = $exception->getResponse()->getContent(false);
        } catch (Throwable) {
            return '';
        }

        $json = json_decode($body, true);

        $text = match (true) {
            is_array($json) && is_string($json['message'] ?? null) => $json['message'],
            is_string($json) => $json,
            default => strip_tags($body),
        };

        return SendFailed::scrub($text);
    }

    /**
     * One Mailgun event in the package's terms, or null for an event the
     * package doesn't act on or one too broken to place. The details kept
     * are those an effect or a report reads; the recipient's address, IP,
     * the message's headers and the envelope stay out, and the delivery
     * status's text is scrubbed of addresses, since a mail server's refusal
     * often quotes one. user-variables is an object when the message carried
     * any and an empty array when it didn't.
     *
     * @param  array<mixed>  $event
     */
    protected function feedback(array $event, ?string $replayKey): ?Feedback
    {
        $name = $event['event'] ?? null;
        $type = is_string($name) ? (static::EVENT_TYPES[$name] ?? null) : null;
        $id = $event['id'] ?? null;
        $timestamp = $event['timestamp'] ?? null;

        if ($type === null || ! is_string($id) || $id === '' || strlen($id) > 255 || ! is_numeric($timestamp)) {
            return null;
        }

        $variables = $event['user-variables'] ?? [];

        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        }

        $variables = is_array($variables) ? $variables : [];
        $status = is_array($event['delivery-status'] ?? null) ? $event['delivery-status'] : [];
        $client = is_array($event['client-info'] ?? null) ? $event['client-info'] : [];
        $flags = is_array($event['flags'] ?? null) ? $event['flags'] : [];

        // Without the angle brackets, as the package stores the id it got
        // when Mailgun took the message.
        $messageId = $this->string(trim((string) $this->string(data_get($event, 'message.headers.message-id')), '<>'));

        return new Feedback(
            providerEventId: $id,
            type: $type,
            occurredAt: Carbon::createFromTimestampUTC((float) $timestamp),
            recipient: $this->string($event['recipient'] ?? null) ?? '',
            messageUuid: $this->string($variables[OutgoingMessage::MESSAGE_KEY] ?? null),
            testSendUuid: $this->string($variables[OutgoingMessage::TEST_KEY] ?? null),
            providerMessageId: $messageId,
            severity: $this->string($event['severity'] ?? null),
            reason: $this->string($event['reason'] ?? null),
            code: is_numeric($status['code'] ?? null) ? (int) $status['code'] : null,
            bot: $this->string($client['bot'] ?? null),
            url: $this->string($event['url'] ?? null, 4000),
            replayKey: $replayKey,
            payload: array_filter([
                'delivery-status' => array_filter([
                    'attempt-no' => is_int($status['attempt-no'] ?? null) ? $status['attempt-no'] : null,
                    'bounce-type' => $this->string($status['bounce-type'] ?? null),
                    'enhanced-code' => $this->string($status['enhanced-code'] ?? null),
                    'message' => is_string($status['message'] ?? null) ? SendFailed::scrub($status['message'], 255) : null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
                'client-info' => array_filter([
                    'client-name' => $this->string($client['client-name'] ?? null),
                    'client-os' => $this->string($client['client-os'] ?? null),
                    'client-type' => $this->string($client['client-type'] ?? null),
                    'device-type' => $this->string($client['device-type'] ?? null),
                    'user-agent' => $this->string($client['user-agent'] ?? null),
                ], fn (?string $value): bool => $value !== null),
                'delivered-at' => is_numeric($event['delivered-at'] ?? null) ? (float) $event['delivered-at'] : null,
                'recipient-provider' => $this->string($event['recipient-provider'] ?? null),
                'is-test-mode' => is_bool($flags['is-test-mode'] ?? null) ? $flags['is-test-mode'] : null,
                'is-routed' => is_bool($flags['is-routed'] ?? null) ? $flags['is-routed'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        );
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

    protected function notYet(): LogicException
    {
        return new LogicException('Reading Mailgun\'s logs and suppressions comes in a later version of the package.');
    }
}
