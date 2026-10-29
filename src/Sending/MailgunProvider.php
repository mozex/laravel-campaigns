<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Mozex\Campaigns\Contracts\CampaignProvider;
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

    public function verifyWebhook(Request $request): bool
    {
        throw $this->notYet();
    }

    public function parseWebhook(Request $request): array
    {
        throw $this->notYet();
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

    protected function notYet(): LogicException
    {
        return new LogicException('Reading Mailgun\'s webhooks, logs and suppressions comes in a later version of the package.');
    }
}
