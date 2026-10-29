<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Content\RenderedEmail;
use Mozex\Campaigns\Content\Renderer;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Sender;

/**
 * Builds one recipient's email from the token of its links: a campaign's
 * message from the revision its campaign froze, a test email from the
 * revision it was made of. The send and the web version both come through
 * here, so the page shows exactly what the email held.
 */
class MessageComposer
{
    public function __construct(protected Renderer $renderer) {}

    /**
     * @throws CampaignsException when the email can't be built: its address
     *                            was forgotten, or it has no revision
     */
    public function outgoing(CampaignMessage|TestSend $email, string $token): OutgoingMessage
    {
        [$content, $person, $subscriber] = $this->parts($email, $token);
        $rendered = $this->renderer->render($content, $person);
        $campaign = $email->campaign;
        $isTest = $email instanceof TestSend;

        return new OutgoingMessage(
            uuid: $email->uuid,
            to: $person->email,
            fromEmail: $campaign === null ? $this->appSender() : $campaign->from_email,
            fromName: $campaign === null ? Sender::name() : $campaign->from_name,
            replyTo: $campaign === null ? Sender::replyTo() : $campaign->reply_to,
            subject: $rendered->subject,
            html: $rendered->html,
            text: $rendered->text,
            unsubscribeUrl: $person->unsubscribeUrl,
            tag: $isTest || $campaign === null ? TestSend::TAG : $campaign->tag,
            trackOpens: ($campaign === null || $campaign->track_opens) && ! $this->opensUntracked($subscriber),
            trackClicks: $campaign === null || $campaign->track_clicks,
            isTest: $isTest,
        );
    }

    /**
     * The email as its recipient got it, for the web version.
     *
     * @throws CampaignsException
     */
    public function rendered(CampaignMessage|TestSend $email, string $token): RenderedEmail
    {
        [$content, $person] = $this->parts($email, $token);

        return $this->renderer->render($content, $person);
    }

    /**
     * The app's sender, for a template's test, held to the marketing domain
     * as every campaign's From is: from another domain the test would fail
     * DMARC and say nothing true about where the campaign lands.
     *
     * @throws CampaignsException
     */
    protected function appSender(): string
    {
        $address = Sender::address() ?? throw CampaignsException::noSendingDomain();

        if (! Sender::isOnDomain($address)) {
            throw CampaignsException::notOnSendingDomain(Sender::domain());
        }

        return $address;
    }

    /**
     * Whether the recipient lives where an open pixel needs consent the app
     * doesn't ask for (campaigns.no_open_tracking_countries, France's by
     * default): their opens go untracked whatever the campaign says.
     */
    protected function opensUntracked(?Subscriber $subscriber): bool
    {
        if ($subscriber?->country === null) {
            return false;
        }

        foreach ((array) config('campaigns.no_open_tracking_countries', []) as $country) {
            if (is_string($country) && strtoupper(trim($country)) === $subscriber->country) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: RevisionContent, 1: Personalization, 2: ?Subscriber}
     *
     * @throws CampaignsException
     */
    protected function parts(CampaignMessage|TestSend $email, string $token): array
    {
        $revision = $this->revision($email);

        if ($email->email === null) {
            throw CampaignsException::recipientForgotten();
        }

        // A preview's row (the web version a preview links to) holds the
        // sample address and shows the sample's details, as the preview did.
        $sample = $email instanceof TestSend && $email->email === Personalization::SAMPLE_EMAIL;

        // A test goes to an app's own address: its subscriber, when it is
        // one, fills the details in as a real recipient's would.
        $subscriber = match (true) {
            $email instanceof CampaignMessage => $email->subscriber,
            $sample => null,
            default => Subscriber::query()->where('email', $email->email)->first(),
        };

        $content = $revision->contentFor($email->locale);

        return [$content, Personalization::forToken(
            token: $token,
            email: $email->email,
            firstName: $sample ? Personalization::SAMPLE_FIRST_NAME : $subscriber?->first_name,
            name: $sample ? Personalization::SAMPLE_NAME : $subscriber?->name,
            locale: $content->locale,
        ), $subscriber];
    }

    protected function revision(CampaignMessage|TestSend $email): Revision
    {
        $revision = $email instanceof CampaignMessage
            ? $email->campaign->frozenRevision
            : $email->revision;

        return $revision ?? throw CampaignsException::noRevision();
    }
}
