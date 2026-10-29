<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\AppliedFeedback;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Rows;

/**
 * What one stored provider event means for the campaign message it is about,
 * its address and the person: a message delivered or failed, an address
 * blocked or a soft bounce counted, a person opted out by a spam report or
 * the provider's own unsubscribe, a human open or click counted as
 * engagement. A test email's events change nothing.
 *
 * It runs inside ProcessFeedbackJob's transaction with the event's row
 * locked, so it applies once, and it reads only the stored event, since
 * events read back from the provider's logs come the same way. Events
 * arrive late and out of order, so every write is conditional and moves
 * forward only. The message's own dates and the person's engagement are
 * the event's time; a consent change is dated when it is applied.
 *
 * A block is a fact about an address, so a bounce or a spam report about
 * mail an address change moved to the person's new row acts on the old
 * address's own row, made bare when it has none, never on the person.
 */
class ApplyFeedbackAction
{
    /**
     * Soft bounces in a row, with no delivery between, that block an address.
     */
    public const SOFT_BOUNCE_LIMIT = 3;

    /**
     * A click this close to the email's delivery, in seconds, is a link
     * scanner checking the email as it arrives.
     */
    public const SCANNER_SECONDS = 10;

    /**
     * A click this close to a click on another link of the same email, in
     * seconds, either way, is a scanner opening every link at once.
     */
    public const BURST_SECONDS = 3;

    /** The consent records' source for a bounce's block. */
    public const BOUNCE = 'bounce';

    /** The consent records' source for a spam report's block and opt-out. */
    public const COMPLAINT = 'complaint';

    /** The consent records' source for the provider's own suppression lists. */
    public const SUPPRESSION = 'provider_suppression';

    public function __construct(
        protected BlockAction $block,
        protected OptOutAction $optOut,
        protected UnsubscribeAction $unsubscribe,
    ) {}

    public function execute(ProviderEvent $event): AppliedFeedback
    {
        $message = $event->message_id === null
            ? null
            : CampaignMessage::query()->with('subscriber')->find($event->message_id);

        if ($message === null) {
            return new AppliedFeedback;
        }

        $at = $event->occurred_at->isFuture() ? now() : $event->occurred_at;

        $this->wentOut($message, $event, $at);

        return match ($event->type) {
            FeedbackType::Accepted => new AppliedFeedback($message->campaign_id),
            FeedbackType::Delivered => $this->delivered($message, $at),
            FeedbackType::Failed => $this->failed($message, $event, $at),
            FeedbackType::Complained => $this->complained($message, $event, $at),
            FeedbackType::Unsubscribed => $this->unsubscribed($message, $event),
            FeedbackType::Opened => $this->opened($message, $event, $at),
            FeedbackType::Clicked => $this->clicked($message, $event, $at),
        };
    }

    /**
     * Any event about a message proves the provider took it, so one its send
     * job never marked sent (an unclear answer, a job that died, one that
     * hasn't finished, an answer that read as "taken nothing") is sent, and
     * is never sent again. One put back to pending lost its link token and
     * its claim then, so its email's links open the invalid page and the
     * pace never counted it: rare, and better than sending it twice.
     */
    protected function wentOut(CampaignMessage $message, ProviderEvent $event, CarbonInterface $at): void
    {
        $moved = CampaignMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', [MessageStatus::Pending, MessageStatus::Queued, MessageStatus::Sending, MessageStatus::Unknown])
            ->update([
                'status' => MessageStatus::Sent,
                'sent_at' => $at,
                'failure_reason' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);

        $providerMessageId = $event->payload['provider_message_id'] ?? null;

        if (is_string($providerMessageId) && $message->provider_message_id === null) {
            CampaignMessage::query()
                ->whereKey($message->id)
                ->whereNull('provider_message_id')
                ->update(['provider_message_id' => mb_substr($providerMessageId, 0, 255), 'updated_at' => now()]);
        }

        // Not even tried once set: on MySQL the update locks the row before
        // its check, and would wait on a freeze holding its recipients' rows.
        // Mail whose address was forgotten with its person records nothing
        // about them.
        if ($moved > 0 && $message->email !== null && $message->subscriber !== null && $message->subscriber->first_mailed_at === null) {
            Subscriber::query()
                ->whereKey($message->subscriber_id)
                ->whereNull('first_mailed_at')
                ->update(['first_mailed_at' => $at, 'updated_at' => now()]);
        }
    }

    /**
     * Delivered, and the address's soft bounces start over: they block only
     * in a row, with no delivery between. A failure already recorded (a late
     * bounce read first) stays, and so does its soft bounce.
     */
    protected function delivered(CampaignMessage $message, CarbonInterface $at): AppliedFeedback
    {
        $delivered = CampaignMessage::query()
            ->whereKey($message->id)
            ->where('status', MessageStatus::Sent)
            ->update(['status' => MessageStatus::Delivered, 'delivered_at' => $at, 'updated_at' => now()]) > 0;

        $address = $this->addressRow($message, make: false);

        // Read first: most deliveries change nothing here, and on MySQL the
        // update would lock the row before its check.
        if ($delivered && $address !== null && $address->soft_bounces > 0) {
            Subscriber::query()
                ->whereKey($address->id)
                ->where('soft_bounces', '>', 0)
                ->update(['soft_bounces' => 0, 'updated_at' => now()]);
        }

        return new AppliedFeedback($message->campaign_id);
    }

    /**
     * A final failure fails the message, a late bounce after its delivery
     * included, and acts on the address as the failure says. A temporary one
     * changes nothing: the provider is still trying.
     */
    protected function failed(CampaignMessage $message, ProviderEvent $event, CarbonInterface $at): AppliedFeedback
    {
        $failure = DeliveryFailure::tryFrom((string) ($event->payload['failure'] ?? '')) ?? DeliveryFailure::Deferred;

        if (! $failure->isFinal()) {
            return new AppliedFeedback($message->campaign_id);
        }

        // Given up on while the sender was being refused: the address did
        // nothing wrong.
        if ($failure === DeliveryFailure::SoftBounce && $this->lastFailure($message, $event) === DeliveryFailure::SenderDeferred) {
            $failure = DeliveryFailure::SenderBlocked;
        }

        $code = $event->payload['code'] ?? null;
        $severity = $event->payload['severity'] ?? null;

        $failedNow = CampaignMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', [MessageStatus::Sent, MessageStatus::Delivered])
            ->update([
                'status' => MessageStatus::Failed,
                'failed_at' => $at,
                'failure_severity' => is_string($severity) ? mb_substr($severity, 0, 32) : null,
                'failure_reason' => $failure->value,
                'failure_code' => is_int($code) && $code >= 0 && $code <= 65535 ? $code : null,
                'updated_at' => now(),
            ]) > 0;

        $bounce = $this->context(static::BOUNCE, $event, $message);
        $suppression = $this->context(static::SUPPRESSION, $event, $message);

        if ($failure === DeliveryFailure::HardBounce) {
            $this->blockAddress($message, BlockReason::HardBounce, $bounce);
        } elseif ($failure === DeliveryFailure::SuppressedBounce) {
            $this->blockAddress($message, BlockReason::HardBounce, $suppression);
        } elseif ($failure === DeliveryFailure::SuppressedComplaint) {
            $this->blockAddress($message, BlockReason::Complaint, $suppression);
        } elseif ($failure === DeliveryFailure::SuppressedUnsubscribe) {
            // The provider holds the address, so the address's row opts out.
            $address = $this->addressRow($message, make: true);

            if ($address !== null) {
                $this->optOut->execute($address, $suppression);
            }
        } elseif ($failure === DeliveryFailure::SoftBounce && $failedNow) {
            // Once per message: only the failure that failed it counts.
            $this->softBounce($message, $bounce);
        }

        return new AppliedFeedback($message->campaign_id, failed: $failedNow ? $failure : null);
    }

    /**
     * The failure the provider reported last about the message before this
     * one: what it was still trying through when it gave up.
     */
    protected function lastFailure(CampaignMessage $message, ProviderEvent $event): ?DeliveryFailure
    {
        $previous = ProviderEvent::query()
            ->where('message_id', $message->id)
            ->where('type', FeedbackType::Failed)
            ->whereKeyNot($event->id)
            ->where('occurred_at', '<=', $event->occurred_at)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['id', 'payload']);

        return DeliveryFailure::tryFrom((string) ($previous?->payload['failure'] ?? ''));
    }

    /**
     * A spam report blocks the address and opts the person out of all the
     * app's marketing. Mail an address change moved blocks only the old
     * address: whoever reads it now may not be the person.
     */
    protected function complained(CampaignMessage $message, ProviderEvent $event, CarbonInterface $at): AppliedFeedback
    {
        $first = CampaignMessage::query()
            ->whereKey($message->id)
            ->whereNull('complained_at')
            ->update(['complained_at' => $at, 'updated_at' => now()]) > 0;

        $context = $this->context(static::COMPLAINT, $event, $message);

        $this->blockAddress($message, BlockReason::Complaint, $context);

        if ($this->isPersonsAddress($message) && $message->subscriber !== null) {
            $this->optOut->execute($message->subscriber, $context);
        }

        return new AppliedFeedback($message->campaign_id, complained: $first);
    }

    /**
     * The provider's own unsubscribe link opts the person out like the
     * package's own, counted against the email when it did.
     */
    protected function unsubscribed(CampaignMessage $message, ProviderEvent $event): AppliedFeedback
    {
        $unsubscribed = $this->unsubscribe->execute($message, $this->context(UnsubscribeAction::PROVIDER, $event, $message));

        return new AppliedFeedback($message->campaign_id, unsubscribed: $unsubscribed);
    }

    /**
     * A person's open: the email's first, and the person's latest. Machines
     * (Apple's privacy prefetch, scanners) count for nothing.
     */
    protected function opened(CampaignMessage $message, ProviderEvent $event, CarbonInterface $at): AppliedFeedback
    {
        if (! $event->is_bot) {
            $this->engaged($message, 'opened_at', 'last_opened_at', $at);
        }

        return new AppliedFeedback($message->campaign_id);
    }

    /**
     * A person's click: the email's first, and the person's latest. A click a
     * link scanner made is marked as a machine's; a click on the package's own
     * pages (unsubscribe, confirmation, web version) is no engagement, since
     * an unsubscribe would otherwise lift a tier or end a sunset.
     */
    protected function clicked(CampaignMessage $message, ProviderEvent $event, CarbonInterface $at): AppliedFeedback
    {
        if (! $event->is_bot && $this->isScanner($message, $event)) {
            $event->forceFill(['is_bot' => true])->save();
        }

        $url = $event->payload['url'] ?? null;

        if (! $event->is_bot && ! (is_string($url) && static::isOwnPage($url))) {
            $this->engaged($message, 'clicked_at', 'last_clicked_at', $at);
        }

        return new AppliedFeedback($message->campaign_id);
    }

    /**
     * Whether a stored link leads to one of the package's own pages, whose
     * token the store replaced with {token}.
     */
    public static function isOwnPage(string $url): bool
    {
        return preg_match('#/campaigns/[ucv]/\{token\}(?:[/?\#]|$)#', $url) === 1;
    }

    /**
     * A click right as the email arrived, or beside a click on another of its
     * links: a scanner checking the email, not a person reading it. Clicks
     * wait a minute before they are applied, so a burst's later clicks are
     * stored by the time its first is checked.
     */
    protected function isScanner(CampaignMessage $message, ProviderEvent $event): bool
    {
        $reportedDeliveredAt = $event->payload['details']['delivered-at'] ?? null;
        $deliveredAt = is_int($reportedDeliveredAt) || is_float($reportedDeliveredAt)
            ? Carbon::createFromTimestampUTC($reportedDeliveredAt)
            : $message->delivered_at;

        if ($deliveredAt !== null && abs($event->occurred_at->diffInSeconds($deliveredAt)) <= static::SCANNER_SECONDS) {
            return true;
        }

        $url = $event->payload['url'] ?? null;

        return ProviderEvent::query()
            ->where('message_id', $message->id)
            ->where('type', FeedbackType::Clicked)
            ->whereKeyNot($event->id)
            ->whereBetween('occurred_at', [
                $event->occurred_at->copy()->subSeconds(static::BURST_SECONDS),
                $event->occurred_at->copy()->addSeconds(static::BURST_SECONDS),
            ])
            ->get(['id', 'payload'])
            ->contains(fn (ProviderEvent $sibling): bool => ($sibling->payload['url'] ?? null) !== $url);
    }

    /**
     * A person read the email: its first open or click is the earliest one
     * seen, and the person's own date moves forward only, which is all it
     * takes to lift their tier or end a sunset. Mail of a person who left
     * (its address gone) records nothing about them.
     */
    protected function engaged(CampaignMessage $message, string $messageColumn, string $subscriberColumn, CarbonInterface $at): void
    {
        CampaignMessage::query()
            ->whereKey($message->id)
            ->where(fn (Builder $query): Builder => $query->whereNull($messageColumn)->orWhere($messageColumn, '>', $at))
            ->update([$messageColumn => $at, 'updated_at' => now()]);

        $latest = $message->subscriber?->getAttribute($subscriberColumn);

        // Read first, as most opens and clicks repeat one already counted:
        // on MySQL the update would lock the row before its check.
        if ($message->email === null || $message->subscriber_id === null || ($latest instanceof CarbonInterface && $latest->greaterThanOrEqualTo($at))) {
            return;
        }

        Subscriber::query()
            ->whereKey($message->subscriber_id)
            ->where(fn (Builder $query): Builder => $query->whereNull($subscriberColumn)->orWhere($subscriberColumn, '<', $at))
            ->update([$subscriberColumn => $at, 'updated_at' => now()]);
    }

    /**
     * One soft bounce against the address; the limit in a row blocks it.
     */
    protected function softBounce(CampaignMessage $message, ConsentContext $context): void
    {
        $address = $this->addressRow($message, make: false);

        if ($address === null) {
            return;
        }

        $counted = Subscriber::query()
            ->whereKey($address->id)
            ->whereNull('blocked_at')
            ->increment('soft_bounces', 1, ['updated_at' => now()]);

        if ($counted === 0) {
            return;
        }

        $count = (int) Subscriber::query()->whereKey($address->id)->value('soft_bounces');

        if ($count >= static::SOFT_BOUNCE_LIMIT) {
            $this->block->execute($address, BlockReason::SoftBounces, $context->withMeta(['soft_bounces' => $count]));
        }
    }

    protected function blockAddress(CampaignMessage $message, BlockReason $reason, ConsentContext $context): void
    {
        $address = $this->addressRow($message, make: true);

        if ($address !== null) {
            $this->block->execute($address, $reason, $context);
        }
    }

    /**
     * Whether the message went to the address its subscriber holds: false
     * for mail an address change moved to the person's new row, which keeps
     * the old address, and for mail whose address was forgotten with its
     * person, since which address it went to is unknown then.
     */
    protected function isPersonsAddress(CampaignMessage $message): bool
    {
        return $message->subscriber !== null && $message->email === $message->subscriber->email;
    }

    /**
     * The row of the address the message went to: its subscriber's, unless
     * an address change moved it there, or the person is gone; then the old
     * address's own row, made holding only the address when $make, so a
     * block stays with the address. Null when the message's address was
     * forgotten with its person: its row is suppressed already.
     */
    protected function addressRow(CampaignMessage $message, bool $make): ?Subscriber
    {
        if ($this->isPersonsAddress($message)) {
            return $message->subscriber;
        }

        if ($message->email === null) {
            return null;
        }

        return Subscriber::findByEmail($message->email)
            ?? ($make ? Rows::firstOrCreate(Subscriber::query(), ['email' => $message->email]) : null);
    }

    protected function context(string $source, ProviderEvent $event, CampaignMessage $message): ConsentContext
    {
        return new ConsentContext(source: $source, meta: [
            'event' => $event->provider_event_id,
            'message' => $message->uuid,
        ]);
    }
}
