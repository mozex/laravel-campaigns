<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Auth\GenericUser;
use Mozex\Campaigns\Contracts\HasCampaignRights;
use Mozex\Campaigns\Enums\Rights;

/**
 * A person an app signs in through its own guard, standing in for its user
 * model: its "rights" attribute is what it answers, and it counts how often
 * it is asked.
 */
class Person extends GenericUser implements HasCampaignRights
{
    public int $asked = 0;

    public function campaignRights(): ?Rights
    {
        $this->asked++;

        $rights = $this->attributes['rights'] ?? null;

        return is_string($rights) ? Rights::tryFrom($rights) : null;
    }
}
