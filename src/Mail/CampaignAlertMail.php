<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plain-text notice to campaigns.alert_address about a campaign that needs
 * someone: it paused itself, got its first spam report, or its queue isn't
 * being worked. Sent from Listeners\SendCampaignAlert.
 */
class CampaignAlertMail extends Mailable
{
    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $heading,
        public array $lines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(text: 'campaigns::mail.alert-text');
    }
}
