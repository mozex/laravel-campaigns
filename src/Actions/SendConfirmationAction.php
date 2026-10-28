<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Jobs\SendConfirmationJob;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;

/**
 * Asks the person to confirm a double opt-in: queues the email that carries
 * the confirmation link. Call it after SubscribeAction leaves someone
 * pending, such as a newsletter form's sign-up.
 *
 * An address gets at most one such email a day, whichever list asks, and a
 * blocked address none. An opted-out person still gets it, since confirming
 * is their way back in. Nothing goes out while sending is off. Each email
 * carries a new link, so an earlier email's link stops working.
 */
class SendConfirmationAction
{
    /**
     * Returns false when no email was queued.
     */
    public function execute(Subscription $subscription): bool
    {
        if (! config('campaigns.enabled') || $subscription->status !== SubscriptionStatus::Pending) {
            return false;
        }

        $now = now();

        // The day's allowance is claimed in one conditional update, so two
        // sign-ups at once can't both send.
        $claimed = Subscriber::query()
            ->whereKey($subscription->subscriber_id)
            ->whereNull('blocked_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('confirmation_sent_at')
                ->orWhere('confirmation_sent_at', '<=', $now->copy()->subDay()))
            ->update(['confirmation_sent_at' => $now, 'updated_at' => $now]);

        if ($claimed === 0) {
            return false;
        }

        SendConfirmationJob::dispatch($subscription->id)->afterCommit();

        return true;
    }
}
