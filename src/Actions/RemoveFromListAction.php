<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Takes a subscriber off a list for the app or an agent. The row goes, so
 * adding the address again later starts afresh; the consent records keep the
 * history. A person's own unsubscribe is an opt-out (OptOutAction), never
 * this.
 */
class RemoveFromListAction
{
    /**
     * Returns false when the subscriber wasn't on the list.
     */
    public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): bool
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $list, $context): bool {
            $removed = Subscription::query()
                ->where('list_id', $list->id)
                ->where('subscriber_id', $subscriber->id)
                ->delete();

            if ($removed === 0) {
                return false;
            }

            ConsentRecord::record($subscriber, ConsentAction::Unsubscribe, $context, $list);

            return true;
        });
    }
}
