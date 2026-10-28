<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Lets an address receive email again and starts its soft-bounce count
 * over. An opt-out, the person's own choice, stays.
 */
class UnblockAction
{
    /**
     * Returns false when the address wasn't blocked.
     */
    public function execute(Subscriber $subscriber, ConsentContext $context): bool
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $context): bool {
            $reason = Subscriber::query()->whereKey($subscriber->id)->lockForUpdate()->value('blocked_reason');

            $changed = Subscriber::query()
                ->whereKey($subscriber->id)
                ->whereNotNull('blocked_at')
                ->update(['blocked_at' => null, 'blocked_reason' => null, 'soft_bounces' => 0, 'updated_at' => now()]);

            if ($changed === 0) {
                return false;
            }

            $subscriber->forceFill(['blocked_at' => null, 'blocked_reason' => null, 'soft_bounces' => 0]);
            $subscriber->syncOriginalAttributes(['blocked_at', 'blocked_reason', 'soft_bounces']);

            ConsentRecord::record($subscriber, ConsentAction::Unblock, $context, meta: ['reason' => $reason]);

            return true;
        });
    }
}
