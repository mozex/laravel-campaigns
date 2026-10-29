<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;

/**
 * A new draft campaign with its first email, and for an upload the images
 * it stored and the ones the email never used.
 */
readonly class CreatedCampaign
{
    /**
     * @param  list<Asset>  $assets
     * @param  list<string>  $unusedImages
     */
    public function __construct(
        public Campaign $campaign,
        public Revision $revision,
        public array $assets = [],
        public array $unusedImages = [],
    ) {}
}
