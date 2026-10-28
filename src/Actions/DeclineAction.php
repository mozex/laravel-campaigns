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
 * Records a sign-up whose marketing box was left unticked: the record joins
 * its list like any other, and the person is opted out of all marketing
 * email, with one entry in the consent records. They are then treated
 * exactly like someone who unsubscribed: a sync or an import never brings
 * them back, and turning the switch on or subscribing again does.
 */
class DeclineAction
{
    public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $list, $context): Subscription {
            $subscription = SubscribeAction::join($subscriber, $list, $context);

            $now = now();

            $optedOut = Subscriber::query()
                ->whereKey($subscriber->id)
                ->whereNull('opted_out_at')
                ->update(['opted_out_at' => $now, 'updated_at' => $now]);

            if ($optedOut === 1) {
                $subscriber->opted_out_at = $now;
                $subscriber->syncOriginalAttribute('opted_out_at');
            }

            ConsentRecord::record($subscriber, ConsentAction::Decline, $context, $list, $subscription->basis);

            return $subscription;
        });
    }
}
