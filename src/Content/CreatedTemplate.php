<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Template;

/**
 * A new template made from an upload, with its first revision, the images
 * it stored and the ones the email never used.
 */
readonly class CreatedTemplate
{
    /**
     * @param  list<Asset>  $assets
     * @param  list<string>  $unusedImages
     */
    public function __construct(
        public Template $template,
        public Revision $revision,
        public array $assets = [],
        public array $unusedImages = [],
    ) {}
}
