<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Symfony\Component\Mime\Email;

/**
 * One campaign email or test email, already rendered. Its HTML goes out as
 * written (never compiled as a view), with the one-click unsubscribe headers,
 * the campaign's tag and the message's uuid as metadata. Never queued: the
 * send job that builds it holds the link's token.
 */
class CampaignEmail extends Mailable
{
    public function __construct(public OutgoingMessage $outgoing)
    {
        // Set again on the built message, after Laravel's own steps: Laravel
        // adds an app-wide reply-to beside the campaign's and puts in a
        // return path, which a sending service's API may send as the From,
        // and it swaps an empty subject for the class name.
        $this->withSymfonyMessage(function (Email $email) use ($outgoing): void {
            $email->text($outgoing->text)->subject($outgoing->subject);
            $email->getHeaders()->remove('Return-Path');

            if ($outgoing->replyTo === null) {
                $email->getHeaders()->remove('Reply-To');
            } else {
                $email->replyTo($outgoing->replyTo);
            }
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->outgoing->fromEmail, $this->outgoing->fromName),
            to: [$this->outgoing->to],
            replyTo: $this->outgoing->replyTo === null ? [] : [$this->outgoing->replyTo],
            subject: $this->outgoing->subject,
            tags: [$this->outgoing->tag],
            metadata: $this->outgoing->metadata(),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->outgoing->html);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->outgoing->headers());
    }
}
