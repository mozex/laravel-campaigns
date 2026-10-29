<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\TextValue;

/**
 * Renames a static list or changes its description. Its key never changes,
 * since campaigns, filters and saved audiences name it by its key; a
 * dynamic list's name is its source's.
 */
class UpdateListAction
{
    public const FIELDS = ['name', 'description'];

    /**
     * @param  array<string, mixed>  $changes  name, and description (null clears it)
     *
     * @throws CampaignsException
     */
    public function execute(CampaignList $list, array $changes): CampaignList
    {
        if ($list->kind === ListKind::Dynamic) {
            throw CampaignsException::dynamicListFixed($list->key);
        }

        $unknown = array_diff(array_keys($changes), static::FIELDS);

        if ($changes === [] || $unknown !== []) {
            throw CampaignsException::invalidValue('change', 'can only be to '.implode(', ', static::FIELDS).($unknown === [] ? '' : ', not '.implode(', ', $unknown)));
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

        $list->fill($values)->save();

        return $list;
    }
}
