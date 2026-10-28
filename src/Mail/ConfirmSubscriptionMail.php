<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Mozex\Campaigns\Campaigns;

/**
 * The double opt-in email. Sent from inside SendConfirmationJob, never
 * queued itself, since it carries the confirmation link.
 */
class ConfirmSubscriptionMail extends Mailable
{
    public function __construct(
        public string $listName,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        /** @var ?string $address */
        $address = config('campaigns.mail.from.address');

        /** @var ?string $name */
        $name = config('campaigns.mail.from.name');

        return new Envelope(
            from: $address ? new Address($address, $name) : null,
            subject: __('campaigns::mail.confirm.subject', ['list' => $this->listName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'campaigns::mail.confirm',
            text: 'campaigns::mail.confirm-text',
            with: ['app' => app(Campaigns::class)->name()],
        );
    }
}
