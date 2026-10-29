<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * A person's unsubscribe from an email, through its link or their mail app's
 * one-click button: they are opted out of all the app's marketing, and the
 * email is marked, since the campaign's numbers count it. A test email's
 * unsubscribe is noted on the test and changes nothing else.
 */
class UnsubscribeAction
{
    /**
     * The consent record's source for a mail app's one-click unsubscribe.
     */
    public const ONE_CLICK = 'one_click';

    /**
     * The consent record's source for the page an email's link opens.
     */
    public const PAGE = 'unsubscribe_page';

    /**
     * The consent record's source for the sending provider's own unsubscribe
     * link, reported by its webhook.
     */
    public const PROVIDER = 'provider_unsubscribe';

    public function __construct(protected OptOutAction $optOut) {}

    /**
     * Returns false when nothing changed: the person had already opted out,
     * the email's subscriber is gone, or the test was already unsubscribed.
     */
    public function execute(CampaignMessage|TestSend $email, ConsentContext $context): bool
    {
        if ($email instanceof TestSend) {
            return TestSend::query()
                ->whereKey($email->id)
                ->whereNull('unsubscribed_at')
                ->update(['unsubscribed_at' => now(), 'updated_at' => now()]) > 0;
        }

        $subscriber = $email->subscriber;

        if ($subscriber === null) {
            return false;
        }

        return $email->getConnection()->transaction(function () use ($email, $subscriber, $context): bool {
            if (! $this->optOut->execute($subscriber, $context->withMeta(['message' => $email->uuid]))) {
                return false;
            }

            CampaignMessage::query()
                ->whereKey($email->id)
                ->whereNull('unsubscribed_at')
                ->update(['unsubscribed_at' => now(), 'updated_at' => now()]);

            return true;
        });
    }
}
