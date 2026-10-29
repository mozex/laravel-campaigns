<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Uploads;

use Mozex\Campaigns\Assets\ImageFile;
use Mozex\Campaigns\Content\EmailContent;

/**
 * An uploaded email ready to save: its content per locale, its relative
 * image paths already pointing at the asset library's addresses, the
 * images to store there, and the files in its images folder no version
 * uses, which are left out.
 */
readonly class EmailUpload
{
    /**
     * @param  list<EmailContent>  $contents
     * @param  list<ImageFile>  $images
     * @param  list<string>  $unusedImages
     */
    public function __construct(
        public array $contents,
        public array $images = [],
        public array $unusedImages = [],
    ) {}
}
