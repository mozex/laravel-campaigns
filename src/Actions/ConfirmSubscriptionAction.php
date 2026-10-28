<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Completes a double opt-in. Confirming is the person's own act, so it also
 * clears an earlier opt-out.
 */
class ConfirmSubscriptionAction
{
    /**
     * The consent record's source for the page the confirmation email's
     * link opens.
     */
    public const PAGE = 'confirmation_page';

    public function __construct(protected OptInAction $optIn) {}

    /**
     * Returns false when the subscription wasn't waiting to be confirmed.
     */
    public function execute(Subscription $subscription, ConsentContext $context): bool
    {
        return $subscription->getConnection()->transaction(function () use ($subscription, $context): bool {
            $now = now();

            $values = [
                'status' => SubscriptionStatus::Subscribed,
                'subscribed_at' => $now,
                'confirmed_at' => $now,
                'confirm_token_hash' => null,
            ];

            $changed = Subscription::query()
                ->whereKey($subscription->id)
                ->where('status', SubscriptionStatus::Pending)
                ->update([...$values, 'updated_at' => $now]);

            if ($changed === 0) {
                return false;
            }

            $subscription->forceFill($values);
            $subscription->syncOriginalAttributes(array_keys($values));
            $subscription->loadMissing(['subscriber', 'list']);

            ConsentRecord::record($subscription->subscriber, ConsentAction::Confirm, $context, $subscription->list, $subscription->basis);

            $this->optIn->execute($subscription->subscriber, $context);

            return true;
        });
    }
}
