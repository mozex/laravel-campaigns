<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Events\SubscriberUnsubscribedEvent;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\AfterCommit;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Stops all marketing email from the app to the person, whatever lists they
 * are on. This is what an unsubscribe does; their list subscriptions stay,
 * for targeting and history. SubscriberUnsubscribedEvent fires after the
 * commit, once per opt-out.
 */
class OptOutAction
{
    /**
     * Returns false when the person had already opted out.
     */
    public function execute(Subscriber $subscriber, ConsentContext $context): bool
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $context): bool {
            $now = now();

            $changed = Subscriber::query()
                ->whereKey($subscriber->id)
                ->whereNull('opted_out_at')
                ->update(['opted_out_at' => $now, 'updated_at' => $now]);

            if ($changed === 0) {
                return false;
            }

            $subscriber->opted_out_at = $now;
            $subscriber->syncOriginalAttribute('opted_out_at');

            ConsentRecord::record($subscriber, ConsentAction::OptOut, $context);

            // Found on its own, so a listener may read its relations under
            // strict models whatever list the caller's model came from.
            AfterCommit::dispatch($subscriber->getConnection(), new SubscriberUnsubscribedEvent(Subscriber::query()->findOrFail($subscriber->id), $context));

            return true;
        });
    }
}
