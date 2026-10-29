<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Resources;

use Laravel\Mcp\Server\Resource;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Mcp\Principal;

/**
 * Every resource is read-only, so a principal with read rights sees them
 * and one with none sees none.
 */
abstract class CampaignsResource extends Resource
{
    public function shouldRegister(): bool
    {
        return Principal::current()?->allows(Rights::Read) ?? false;
    }
}
