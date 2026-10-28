<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;

/**
 * Forgets the person behind a subscriber, for a deleted account or an
 * address the person has left. Their lists, details, IP addresses, browsers
 * and the addresses on their sent mail go, and mail still waiting is never
 * sent. The consent records and the campaigns' numbers stay.
 *
 * A blocked or opted-out address keeps its row, stripped to the address and
 * that state, so a later import of the same address can't mail it again.
 * Any other address is deleted.
 */
class ForgetSubscriberAction
{
    public function execute(Subscriber $subscriber): void
    {
        $subscriber->getConnection()->transaction(function () use ($subscriber): void {
            $fresh = Subscriber::query()->lockForUpdate()->find($subscriber->id);

            if ($fresh === null) {
                return;
            }

            $now = now();
            $messages = CampaignMessage::query()->where('subscriber_id', $fresh->id);

            // Before the row is deleted, which nulls the messages' key and
            // leaves nothing to find them by.
            (clone $messages)->where('status', MessageStatus::Pending)->update(['status' => MessageStatus::Skipped, 'updated_at' => $now]);
            ProviderEvent::query()->whereIn('message_id', (clone $messages)->select('id'))->update(['payload' => null]);
            (clone $messages)->update(['email' => null, 'updated_at' => $now]);

            ConsentRecord::query()->where('subscriber_id', $fresh->id)->update(['ip_address' => null, 'user_agent' => null]);
            Subscription::query()->where('subscriber_id', $fresh->id)->delete();

            if (! $fresh->isSuppressed()) {
                $fresh->delete();

                return;
            }

            $fresh->subscribable()->dissociate();
            $fresh->forceFill([
                'name' => null,
                'first_name' => null,
                'locale' => null,
                'timezone' => null,
                'country' => null,
                'custom_fields' => null,
                'last_opened_at' => null,
                'last_clicked_at' => null,
                'last_active_at' => null,
                'first_mailed_at' => null,
                'confirmation_sent_at' => null,
            ])->save();
        });
    }
}
