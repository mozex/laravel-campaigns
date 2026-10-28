<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Puts a subscriber on a list, subscribed, or waiting to confirm on a double
 * opt-in list. A subscription that already exists is left as it is, so a
 * sync or an import never changes what someone chose.
 *
 * When the person asked for it themselves (the context's accepted is true)
 * this is also the resubscribe: it clears an earlier opt-out once the
 * subscription is active. On a double opt-in list that waits for the
 * confirmation. A block is never cleared.
 */
class SubscribeAction
{
    public function __construct(protected OptInAction $optIn) {}

    public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $list, $context): Subscription {
            $subscription = $this->join($subscriber, $list, $context);

            if ($subscription->wasRecentlyCreated) {
                ConsentRecord::record($subscriber, ConsentAction::Subscribe, $context, $list, $subscription->basis);
            }

            if ($context->accepted === true && $subscription->status === SubscriptionStatus::Subscribed) {
                $this->optIn->execute($subscriber, $context);
            }

            return $subscription;
        });
    }

    /**
     * The subscriber's place on the list, made with the list's defaults when
     * there is none. Safe when two requests add the same address at once.
     */
    public static function join(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
    {
        $status = $list->default_status;

        return Subscription::query()->firstOrCreate([
            'list_id' => $list->id,
            'subscriber_id' => $subscriber->id,
        ], [
            'status' => $status,
            'basis' => $context->basis ?? $list->default_basis,
            'source' => $context->source,
            'subscribed_at' => $status === SubscriptionStatus::Subscribed ? now() : null,
        ]);
    }
}
