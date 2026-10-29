<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Models\Template;

/**
 * Hides a template from the list of templates to start from, or brings it
 * back. Nothing is deleted: campaigns made from it keep their own copy.
 */
class ArchiveTemplateAction
{
    /**
     * Returns false when the template was already in that state.
     */
    public function execute(Template $template, bool $archived = true): bool
    {
        $now = now();

        $changed = Template::query()
            ->whereKey($template->id)
            ->when(
                $archived,
                fn (Builder $query): Builder => $query->whereNull('archived_at'),
                fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
            )
            ->update(['archived_at' => $archived ? $now : null, 'updated_at' => $now]);

        if ($changed === 0) {
            return false;
        }

        $template->archived_at = $archived ? $now : null;
        $template->syncOriginalAttribute('archived_at');

        return true;
    }
}
