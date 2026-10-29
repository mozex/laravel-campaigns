<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Uploads;

use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Revision;

/**
 * What saving an uploaded email made: the revision, the library's assets
 * its images became, and the images folder's files no version uses.
 */
readonly class UploadedRevision
{
    /**
     * @param  list<Asset>  $assets
     * @param  list<string>  $unusedImages
     */
    public function __construct(
        public Revision $revision,
        public array $assets,
        public array $unusedImages,
    ) {}
}
