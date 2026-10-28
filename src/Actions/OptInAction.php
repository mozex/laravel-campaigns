<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Clears an earlier opt-out. Only a deliberate act of the person may do so
 * (subscribing again, turning the switch on, confirming a double opt-in,
 * signing up with the box ticked), never a sync or an import. A block is
 * never cleared here.
 */
class OptInAction
{
    /**
     * Returns false when the person hadn't opted out.
     */
    public function execute(Subscriber $subscriber, ConsentContext $context): bool
    {
        return $subscriber->getConnection()->transaction(function () use ($subscriber, $context): bool {
            $changed = Subscriber::query()
                ->whereKey($subscriber->id)
                ->whereNotNull('opted_out_at')
                ->update(['opted_out_at' => null, 'updated_at' => now()]);

            if ($changed === 0) {
                return false;
            }

            $subscriber->opted_out_at = null;
            $subscriber->syncOriginalAttribute('opted_out_at');

            ConsentRecord::record($subscriber, ConsentAction::OptIn, $context);

            return true;
        });
    }
}
