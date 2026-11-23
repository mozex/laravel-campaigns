<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\TextValue;

/**
 * Renames a static list or changes its description, and marks any list a
 * default list or not. Its key never changes, since campaigns, filters and
 * saved audiences name it by its key; a dynamic list's name is its
 * source's. A default list receives every campaign created without lists,
 * so an archived list can't be one: the row is locked while it is read, and
 * archiving takes the mark away.
 */
class UpdateListAction
{
    public const FIELDS = ['name', 'description', 'is_default'];

    /**
     * @param  array<string, mixed>  $changes  name, description (null clears it) and is_default
     *
     * @throws CampaignsException
     */
    public function execute(CampaignList $list, array $changes): CampaignList
    {
        $unknown = array_diff(array_keys($changes), static::FIELDS);

        if ($changes === [] || $unknown !== []) {
            throw CampaignsException::invalidValue('change', 'can only be to '.implode(', ', static::FIELDS).($unknown === [] ? '' : ', not '.implode(', ', $unknown)));
        }

        // A dynamic list takes only the mark; its name and description are
        // the app's.
        if ($list->kind === ListKind::Dynamic && array_diff(array_keys($changes), ['is_default']) !== []) {
            throw CampaignsException::dynamicListFixed($list->key);
        }

        $values = [];

        if (array_key_exists('name', $changes)) {
            if (! is_string($changes['name'])) {
                throw CampaignsException::invalidValue('name', 'must be text');
            }

            $values['name'] = TextValue::required('name', $changes['name'], 255);
        }

        if (array_key_exists('description', $changes)) {
            $description = $changes['description'];

            if ($description !== null && ! is_string($description)) {
                throw CampaignsException::invalidValue('description', 'must be text or null');
            }

            $values['description'] = TextValue::optional('description', $description, 5000);
        }

        if (array_key_exists('is_default', $changes)) {
            if (! is_bool($changes['is_default'])) {
                throw CampaignsException::invalidValue('is_default', 'must be true or false');
            }

            $values['is_default'] = $changes['is_default'];
        }

        return $list->getConnection()->transaction(function () use ($list, $values): CampaignList {
            // The archive's update waits for this lock, so a list archived
            // in the meantime is seen here, and one archived after loses
            // the mark there.
            $locked = CampaignList::query()->whereKey($list->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::invalidValue('list', 'was deleted');

            if (($values['is_default'] ?? false) && $locked->archived_at !== null) {
                throw CampaignsException::archivedListDefault($locked->key);
            }

            $locked->fill($values)->save();
            $list->setRawAttributes($locked->getAttributes(), sync: true);

            return $list;
        });
    }
}
