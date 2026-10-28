<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * "Subscribe again" on the page an email's link opens. The person asks for
 * the app's marketing again, so their opt-out clears, even while a double
 * opt-in waits, since the email's link already proves the address is theirs.
 * A block never clears, and the lists they are on stay as they are: a list
 * an agent took them off stays off.
 *
 * Only from the address the person still holds: whoever reads an address
 * the person moved away from, or one whose person was forgotten, can't sign
 * them back up.
 */
class ResubscribeAction
{
    public function __construct(protected OptInAction $optIn) {}

    public static function allows(CampaignMessage $message): bool
    {
        $subscriber = $message->subscriber;

        return $subscriber !== null
            && $subscriber->blocked_at === null
            && $message->email !== null
            && $message->email === $subscriber->email;
    }

    /**
     * Returns false when the person can't subscribe again from this email.
     */
    public function execute(CampaignMessage $message, ConsentContext $context): bool
    {
        $subscriber = $message->subscriber;

        if ($subscriber === null || ! static::allows($message)) {
            return false;
        }

        $this->optIn->execute($subscriber, $context->withMeta(['message' => $message->uuid]));

        return true;
    }
}
