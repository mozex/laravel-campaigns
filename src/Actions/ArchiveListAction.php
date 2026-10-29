<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\CampaignList;

/**
 * Archives a static list, or brings it back. An archived list is hidden
 * from the lists agents pick from and takes no more contacts from agents;
 * nothing is deleted: its people stay on it, and the app's own code and the
 * campaigns that target it go on as before.
 */
class ArchiveListAction
{
    /**
     * Returns false when the list was already in that state.
     *
     * @throws CampaignsException for a dynamic list, which follows the app's config
     */
    public function execute(CampaignList $list, bool $archived = true): bool
    {
        if ($list->kind === ListKind::Dynamic) {
            throw CampaignsException::dynamicListFixed($list->key);
        }

        $now = now();

        $changed = CampaignList::query()
            ->whereKey($list->id)
            ->when(
                $archived,
                fn (Builder $query): Builder => $query->whereNull('archived_at'),
                fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
            )
            ->update(['archived_at' => $archived ? $now : null, 'updated_at' => $now]);

        if ($changed === 0) {
            return false;
        }

        $list->archived_at = $archived ? $now : null;
        $list->syncOriginalAttribute('archived_at');

        return true;
    }
}
