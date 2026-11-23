<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Mozex\Campaigns\Enums\Rights;

/**
 * Who may use the campaigns server: implemented by the app's user model, and
 * by the package's own tokens. Signing in through one of
 * campaigns.mcp.guards isn't enough; a principal whose model doesn't
 * implement this has no rights and is refused before anything is listed.
 *
 * The package asks Laravel's Gate nothing, so a Gate::before() or a
 * permission package's super-admin hook grants nothing here unless this
 * method asks for it.
 */
interface HasCampaignRights
{
    /**
     * Full rights, read-only rights, or null for none. The server's route
     * runs none of the app's own middleware, so account checks such as a ban
     * belong here. It is read once a request.
     */
    public function campaignRights(): ?Rights;
}
