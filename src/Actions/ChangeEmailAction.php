<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Rows;

/**
 * Moves a person to a new address. Their choices go with them (their lists,
 * an opt-out, their details and consent records); a block stays with the
 * address it belongs to. Mail already frozen for the old address in a
 * running campaign is skipped.
 *
 * When the old row holds nothing that must stay with the old address, the
 * address simply changes. Otherwise the person moves to the row of the new
 * address, a new one or one that exists already, where the stricter of the
 * two states wins, and the old row is forgotten (ForgetSubscriberAction): kept,
 * stripped, when it is blocked or opted out, deleted when not.
 */
class ChangeEmailAction
{
    /**
     * How strict each list status is, for when both rows are on one list.
     */
    protected const STRICTNESS = [
        'subscribed' => 0,
        'pending' => 1,
        'unsubscribed' => 2,
    ];

    public function __construct(protected ForgetSubscriberAction $forget) {}

    /**
     * Returns the row that now holds the person.
     *
     * @throws CampaignsException when the address isn't valid, or belongs to another record
     */
    public function execute(Subscriber $subscriber, string $email, ConsentContext $context): Subscriber
    {
        $address = EmailAddress::normalize($email) ?? throw CampaignsException::invalidEmail();

        if ($address === $subscriber->email) {
            return $subscriber;
        }

        return $subscriber->getConnection()->transaction(function () use ($subscriber, $address, $context): Subscriber {
            // Whether the address can simply change depends on a block or an
            // opt-out landing at this moment, so read them under a lock.
            $current = Subscriber::query()->lockForUpdate()->find($subscriber->id) ?? throw CampaignsException::subscriberGone();
            $subscriber->setRawAttributes($current->getAttributes(), true);

            // Another request moved it there a moment ago.
            if ($subscriber->email === $address) {
                return $subscriber;
            }

            $existing = Subscriber::query()->where('email', $address)->lockForUpdate()->first();

            if ($existing?->subscribable_type !== null) {
                throw CampaignsException::addressTaken();
            }

            CampaignMessage::query()
                ->where('subscriber_id', $subscriber->id)
                ->where('status', MessageStatus::Pending)
                ->update(['status' => MessageStatus::Skipped, 'updated_at' => now()]);

            if ($existing === null && ! $subscriber->isSuppressed()) {
                $subscriber->update(['email' => $address]);

                return $subscriber;
            }

            $target = $existing ?? Rows::firstOrCreate(Subscriber::query(), ['email' => $address]);

            if ($target->subscribable_type !== null) {
                throw CampaignsException::addressTaken();
            }

            $this->movePerson($subscriber, $target);
            $this->moveSubscriptions($subscriber, $target);

            // The person's consent history follows them; a block's stays with
            // the address it was about.
            ConsentRecord::query()
                ->where('subscriber_id', $subscriber->id)
                ->whereNotIn('action', [ConsentAction::Block, ConsentAction::Unblock])
                ->update(['subscriber_id' => $target->id]);

            // The old address stays opted out, so it isn't mailed if it comes
            // back through an import; this entry is its record of why. It
            // names no row, so two kept addresses can't be tied to one person.
            if ($subscriber->opted_out_at !== null) {
                ConsentRecord::record($subscriber, ConsentAction::OptOut, $context, meta: ['address_changed' => true]);
            }

            $this->forget->execute($subscriber);

            return $target;
        });
    }

    /**
     * The record link, the details the new row lacks, the latest activity
     * and an opt-out. The link goes first: one record links to one row.
     */
    protected function movePerson(Subscriber $from, Subscriber $to): void
    {
        $record = $from->subscribable_type === null ? null : [$from->subscribable_type, $from->subscribable_id];

        if ($record !== null) {
            $from->forceFill(['subscribable_type' => null, 'subscribable_id' => null])->save();
            $to->forceFill(['subscribable_type' => $record[0], 'subscribable_id' => $record[1]]);
        }

        foreach (['name', 'first_name', 'locale', 'timezone', 'country'] as $field) {
            $to->setAttribute($field, $to->getAttribute($field) ?? $from->getAttribute($field));
        }

        if ($from->custom_fields !== null) {
            $to->custom_fields = [...$from->custom_fields, ...$to->custom_fields ?? []];
        }

        if ($from->last_active_at !== null && ($to->last_active_at === null || $from->last_active_at->isAfter($to->last_active_at))) {
            $to->last_active_at = $from->last_active_at;
        }

        $to->opted_out_at ??= $from->opted_out_at;

        $to->save();
    }

    protected function moveSubscriptions(Subscriber $from, Subscriber $to): void
    {
        // Locking reads: a plain read inside an app's MySQL transaction can miss
        // a subscription made after its snapshot, and moving onto it would
        // break the unique pair.
        $theirs = Subscription::query()->where('subscriber_id', $to->id)->lockForUpdate()->get()->keyBy('list_id');

        foreach (Subscription::query()->where('subscriber_id', $from->id)->lockForUpdate()->get() as $subscription) {
            $existing = $theirs->get($subscription->list_id);

            if ($existing === null) {
                $subscription->update(['subscriber_id' => $to->id]);

                continue;
            }

            if (static::STRICTNESS[$subscription->status->value] > static::STRICTNESS[$existing->status->value]) {
                $existing->update(['status' => $subscription->status]);
            }

            $subscription->delete();
        }
    }
}
