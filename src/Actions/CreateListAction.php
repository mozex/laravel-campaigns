<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\TextValue;

/**
 * A new static list, for contacts an agent, an import or the app adds. A
 * dynamic list is never made here: its rows come from the app's records, and
 * the package makes it the first time its source is needed.
 */
class CreateListAction
{
    /**
     * @param  ConsentBasis  $basis  The basis a contact joins with when whoever adds them names none.
     *
     * @throws CampaignsException for a key that is invalid, taken, or a list source's
     */
    public function execute(
        string $key,
        string $name,
        ?string $description = null,
        bool $doubleOptIn = false,
        ConsentBasis $basis = ConsentBasis::Consent,
    ): CampaignList {
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidKey($key);
        }

        // Its source would find a static list under its key and refuse to
        // sync for good.
        if (array_key_exists($key, (array) config('campaigns.list_sources', []))) {
            throw CampaignsException::listSourceKey($key);
        }

        $name = TextValue::required('name', $name, 255);
        $description = TextValue::optional('description', $description, 5000);

        if (CampaignList::query()->where('key', $key)->exists()) {
            throw CampaignsException::keyTaken('list', $key);
        }

        try {
            return CampaignList::create([
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'kind' => ListKind::Static,
                'double_opt_in' => $doubleOptIn,
                'default_status' => $doubleOptIn ? SubscriptionStatus::Pending : SubscriptionStatus::Subscribed,
                'default_basis' => $basis,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw CampaignsException::keyTaken('list', $key);
        }
    }
}
