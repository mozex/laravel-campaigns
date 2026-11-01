<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * One fully rendered email, ready for the driver. The uuid travels with the
 * message so the service's events lead back to it; recipients can see it,
 * so it is an opaque id and nothing more. The double opt-in email has no
 * unsubscribe address: it is no marketing, and the person is on no list yet.
 * trackOpens and trackClicks say whether the package put its own open pixel
 * and tracked links in the HTML; a driver turns its service's tracking off
 * whatever they say.
 */
readonly class OutgoingMessage
{
    /**
     * The metadata key a campaign's message carries its uuid under.
     */
    public const MESSAGE_KEY = 'campaign-message';

    /**
     * The metadata key a test email carries its uuid under, so the
     * service's events for it lead to the test, never a campaign's numbers.
     */
    public const TEST_KEY = 'campaign-test';

    public function __construct(
        public string $uuid,
        public string $to,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $replyTo,
        public string $subject,
        public string $html,
        public string $text,
        public ?string $unsubscribeUrl,
        public string $tag,
        public bool $trackOpens,
        public bool $trackClicks,
        public bool $isTest = false,
    ) {}

    /**
     * The headers a send carries besides From, To, Reply-To and the
     * subject: one-click unsubscribe (RFC 8058) with a single https address,
     * when the email has one.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        if ($this->unsubscribeUrl === null) {
            return [];
        }

        return [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * What travels with the message for the service to echo back in its
     * events.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return [($this->isTest ? static::TEST_KEY : static::MESSAGE_KEY) => $this->uuid];
    }

    /**
     * The email as a mailbox gets it, the service's own options left out:
     * what the pre-send spam check scores.
     */
    public function toEmail(): Email
    {
        $email = (new Email)
            ->from(new Address($this->fromEmail, $this->fromName ?? ''))
            ->to($this->to)
            ->subject($this->subject)
            ->text($this->text)
            ->html($this->html);

        if ($this->replyTo !== null) {
            $email->replyTo($this->replyTo);
        }

        foreach ($this->headers() as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }
}
