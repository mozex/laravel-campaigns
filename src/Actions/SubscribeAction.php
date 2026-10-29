<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Rows;

/**
 * Puts a subscriber on a list, subscribed, or waiting to confirm on a double
 * opt-in list. A subscription that already exists is left as it is, so a
 * sync or an import never changes what someone chose.
 *
 * When the person asked for it themselves (the context's accepted is true)
 * this is also the resubscribe: it clears an earlier opt-out, except while a
 * double opt-in waits for its confirmation, which clears it instead. A block
 * is never cleared.
 */
class SubscribeAction
{
    public function __construct(protected OptInAction $optIn) {}

    public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
    {
        // A refusal subscribing someone would record the opposite of what
        // they chose.
        if ($context->accepted === false) {
            throw CampaignsException::declinedSubscribe();
        }

        return $subscriber->getConnection()->transaction(function () use ($subscriber, $list, $context): Subscription {
            $subscription = $this->join($subscriber, $list, $context);

            if ($subscription->wasRecentlyCreated) {
                ConsentRecord::record($subscriber, ConsentAction::Subscribe, $context, $list, $subscription->basis);
            }

            if ($context->accepted === true && $subscription->status !== SubscriptionStatus::Pending) {
                // A new subscription's basis is the sign-up's; an existing
                // one's is older than this act, which is consent.
                $this->optIn->execute($subscriber, $context, $subscription->wasRecentlyCreated ? $subscription->basis : null);
            }

            return $subscription;
        });
    }

    /**
     * The subscriber's place on the list, made with the list's defaults when
     * there is none.
     *
     * @throws CampaignsException for a dynamic list and a subscriber no app record stands behind
     */
    public function join(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
    {
        // A dynamic list holds the app's records only, and nothing could take
        // a contact added by hand off it again.
        if ($list->kind === ListKind::Dynamic && $subscriber->subscribable_type === null) {
            throw CampaignsException::dynamicList($list->key);
        }

        $status = $list->default_status;

        return Rows::firstOrCreate(Subscription::query(), [
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
