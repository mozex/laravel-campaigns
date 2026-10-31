<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mail;

use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Support\Sender;

/**
 * The double opt-in email, rendered in the person's language for the active
 * driver, which sends every email the package sends. Built inside
 * SendConfirmationJob, never queued, since it carries the confirmation link.
 * It has no unsubscribe header (it is no marketing, and the person is on no
 * list yet) and is never tracked: an open pixel where the law asks for
 * consent to one, and a service's click redirect would keep the confirmation
 * token in its logs. Its uuid is stored nowhere, so the service's events
 * about it match nothing and are dropped.
 */
class ConfirmSubscriptionMail
{
    use Localizable;

    /**
     * The tag the service files it under, apart from every campaign's.
     */
    public const TAG = 'campaigns-confirmation';

    public function __construct(
        public string $listName,
        public string $url,
    ) {}

    /**
     * @throws CampaignsException when no sender address is configured
     */
    public function outgoing(string $to, string $locale): OutgoingMessage
    {
        return $this->withLocale($locale, function () use ($to): OutgoingMessage {
            $data = ['listName' => $this->listName, 'url' => $this->url, 'app' => app(Campaigns::class)->name()];

            return new OutgoingMessage(
                uuid: (string) Str::uuid(),
                to: $to,
                fromEmail: Sender::address() ?? throw CampaignsException::noSendingDomain(),
                fromName: Sender::name(),
                replyTo: Sender::replyTo(),
                subject: (string) __('campaigns::mail.confirm.subject', ['list' => $this->listName]),
                html: view('campaigns::mail.confirm', $data)->render(),
                text: view('campaigns::mail.confirm-text', $data)->render(),
                unsubscribeUrl: null,
                tag: static::TAG,
                trackOpens: false,
                trackClicks: false,
            );
        });
    }
}
