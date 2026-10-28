<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Stops all email to an address that can't or mustn't receive it: a hard
 * bounce, a spam report, repeated soft bounces. A block belongs to the
 * address, so it stays with it when the person moves to another one.
 */
class BlockAction
{
    /**
     * Returns false when the address was already blocked; its first reason
     * stands.
     */
    public function execute(Subscriber $subscriber, BlockReason $reason, ConsentContext $context): bool
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $reason, $context): bool {
            $now = now();

            $changed = Subscriber::query()
                ->whereKey($subscriber->id)
                ->whereNull('blocked_at')
                ->update(['blocked_at' => $now, 'blocked_reason' => $reason, 'updated_at' => $now]);

            if ($changed === 0) {
                return false;
            }

            $subscriber->forceFill(['blocked_at' => $now, 'blocked_reason' => $reason]);
            $subscriber->syncOriginalAttributes(['blocked_at', 'blocked_reason']);

            ConsentRecord::record($subscriber, ConsentAction::Block, $context, meta: ['reason' => $reason->value]);

            return true;
        });
    }
}
