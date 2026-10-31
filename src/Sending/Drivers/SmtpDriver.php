<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Illuminate\Mail\Mailer;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Mail\CampaignEmail;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends through an SMTP server by way of the app's campaigns mailer
 * (campaigns.mail.mailer), which must use Laravel's smtp transport
 * (Symfony's EsmtpTransport): a relay in production, a mail catcher locally.
 * The log and array mailers are allowed outside production, since they
 * deliver nothing. The tag and metadata headers Laravel's mailable adds
 * (X-Tag, X-Metadata-*) are stripped: only a service's API transport turns
 * them into its own fields, and SMTP would send them as they are.
 *
 * SMTP tells only what is refused while the email is handed over, so the
 * driver has none of the optional abilities: its emails end at sent, and a
 * bounce or spam report that comes later never reaches the package.
 *
 * A failure is read from the server's reply code alone, never its words:
 * Symfony's transport throws with the code as the exception's (0 for none),
 * and the last line of the driver's own side in its debug log says which
 * command the code answered. Before each send the connection is made sure
 * of: a transport not yet started is started (greeting, EHLO, STARTTLS,
 * login), and one a queue worker kept open is asked NOOP and started again
 * when it doesn't answer, so a connection the server dropped never fails a
 * send midway; a 5xx while starting refuses the sender (a refused login,
 * say) and a 4xx or no code took nothing. Once the email is being sent, a
 * 4xx took nothing; a 5xx to MAIL FROM refuses the sender too; a 5xx to the
 * recipient or to the email refuses this email for good, a hard bounce when
 * its enhanced status code (RFC 3463's standard code after the reply code)
 * names a bad mailbox; and no code leaves it unclear, since the server may
 * have taken it. An address with characters outside ASCII before the @,
 * which a server without SMTPUTF8 can't take, is refused for good before
 * anything is sent.
 */
class SmtpDriver implements CampaignDriver
{
    use UsesCampaignsMailer;

    /**
     * The seconds each read of the server's reply may wait, which a smtp
     * mailer gets when the app gave it none (DriverManager::boundMailer()):
     * SMTP has no limit on a whole send, so a server that stops answering
     * would hold the send job until its own timeout.
     */
    public const TIMEOUT = 10;

    /**
     * RFC 3463's enhanced status codes that say the mailbox doesn't exist:
     * a bad mailbox, a bad system, a bad mailbox syntax, and a null MX.
     */
    protected const BAD_MAILBOX = ['5.1.1', '5.1.2', '5.1.3', '5.1.10'];

    public function send(OutgoingMessage $message): SendReceipt
    {
        $mailer = $this->campaignsMailer(EsmtpTransport::class, 'SMTP', 'the smtp transport');
        $transport = $mailer instanceof Mailer ? $mailer->getSymfonyTransport() : null;

        if ($transport instanceof EsmtpTransport) {
            $this->connect($transport);
        }

        $email = (new CampaignEmail($message))->withSymfonyMessage(function (Email $email): void {
            $headers = $email->getHeaders();
            $names = [];

            foreach ($headers->all() as $header) {
                if ($header instanceof TagHeader || $header instanceof MetadataHeader) {
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
        } catch (InvalidArgumentException) {
            // Thrown before MAIL FROM is written, the only one a send can
            // meet: nothing went, and sending again can't help.
            throw new SendFailed(SendFailure::Rejected, 'The SMTP server offers no SMTPUTF8, so it can\'t take an address with characters outside ASCII before the @.');
        } catch (Throwable $exception) {
            // Anything else, such as an app's MessageSent listener failing
            // after the server took the message: reported, since it is a bug
            // somewhere, and unclear, since the message may have gone.
            report($exception);

            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        }

        $id = trim((string) $sent?->getMessageId(), "<> \t");

        if ($id === '') {
            // A MessageSending listener can stop a message, and a faked
            // mailer records it instead: either way there is no receipt.
            throw new SendFailed(SendFailure::Unclear, 'The mailer gave no message id, so whether the message went is unknown.');
        }

        return new SendReceipt($id);
    }

    /**
     * Makes sure of the connection before the email goes: started (which
     * does nothing when it already is), then a NOOP, which a connection the
     * server dropped while the worker waited fails; such a connection is
     * closed and started again.
     *
     * Symfony's own checks are turned off on this transport: it would start
     * the connection again after every 100 emails, after an email went and
     * outside its own error handling, and ping it inside the send, so a
     * refused restart would read as the email's own failure (a 4xx as
     * nothing taken, sending it twice). The NOOP here is all a send needs.
     *
     * @throws SendFailed
     */
    protected function connect(EsmtpTransport $transport): void
    {
        $transport->setRestartThreshold(PHP_INT_MAX);
        $transport->setPingThreshold(PHP_INT_MAX);

        $this->start($transport);

        try {
            $transport->executeCommand("NOOP\r\n", [250]);
        } catch (TransportExceptionInterface) {
            $transport->stop();
            $this->start($transport);
        }
    }

    /**
     * @throws SendFailed when the server can't be reached (nothing went) or
     *                    refuses the mailer's login or sender (nothing can
     *                    go until someone acts)
     */
    protected function start(EsmtpTransport $transport): void
    {
        try {
            $transport->start();
        } catch (TransportExceptionInterface $exception) {
            $code = (int) $exception->getCode();

            if ($code >= 500 && $code < 600) {
                throw new SendFailed(SendFailure::DomainRefused, "The SMTP server refused the campaigns mailer while connecting ({$code}): its login or its sender, say.", $code);
            }

            throw new SendFailed(SendFailure::NotAccepted, 'The SMTP server couldn\'t be reached or took nothing while connecting'.($code > 0 ? " ({$code})" : '').', so the email can go later.', $code > 0 ? $code : null);
        }
    }

    /**
     * What a failure while the email was being sent means, by its reply
     * code and the command it answered. The code is the exception's own; the
     * enhanced status code is read from the reply Symfony quotes in its
     * message, the one place it keeps it.
     */
    protected function failure(TransportExceptionInterface $exception): SendFailed
    {
        $code = (int) $exception->getCode();

        if ($code >= 400 && $code < 500) {
            return new SendFailed(SendFailure::NotAccepted, "The SMTP server answered {$code}, taking nothing, so the email can go later.", $code);
        }

        if ($code < 500 || $code >= 600) {
            return new SendFailed(SendFailure::Unclear, 'The connection to the SMTP server failed while the email was being sent, so it may have gone.', $code > 0 ? $code : null);
        }

        $command = static::failedCommand($exception);

        if ($command === 'MAIL') {
            return new SendFailed(SendFailure::DomainRefused, "The SMTP server refused the sender ({$code}), so no email can go from it until someone acts.", $code);
        }

        $enhanced = static::enhancedCode($exception->getMessage(), $code);
        // Only a refusal of the recipient, or of the email sent to it, can
        // name the recipient's mailbox.
        $badMailbox = in_array($command, ['RCPT', '.'], true) && in_array($enhanced, static::BAD_MAILBOX, true);

        return new SendFailed(
            SendFailure::Rejected,
            "The SMTP server refused the email for good ({$code}".($enhanced === null ? '' : " {$enhanced}").')'.($badMailbox ? ': the mailbox doesn\'t exist.' : '.'),
            $code,
            $badMailbox ? DeliveryFailure::HardBounce : null,
        );
    }

    /**
     * The command the failure answered: the first word of the last line the
     * driver's side wrote in the exception's debug log (MAIL, RCPT, DATA, or
     * "." for the email's end), or null when there is none.
     */
    protected static function failedCommand(TransportExceptionInterface $exception): ?string
    {
        preg_match_all('/^\[[^\]\n]*\] > (\S+)/m', $exception->getDebug(), $matches);
        $last = end($matches[1]);

        return is_string($last) ? strtoupper($last) : null;
    }

    /**
     * The enhanced status code (RFC 3463: class, subject and detail, as in
     * 5.1.1) that follows the reply code at the start of the server's reply,
     * on its first line or a continued one, or null when it gave none.
     */
    protected static function enhancedCode(string $reply, int $code): ?string
    {
        $class = intdiv($code, 100);

        return preg_match('/(?:^|[\s"])'.$code.'[ -]('.$class.'\.\d{1,3}\.\d{1,3})(?=\s|$)/', $reply, $matches) === 1 ? $matches[1] : null;
    }
}
