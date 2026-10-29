<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Support\TextValue;

/**
 * Opens an upload link: a row a file may arrive through once, within
 * Upload::LINK_MINUTES. The app's endpoint signs the link to it.
 */
class CreateUploadAction
{
    /**
     * @throws CampaignsException
     */
    public function execute(UploadPurpose $purpose, ?string $author = null): Upload
    {
        return Upload::create([
            'purpose' => $purpose,
            'author' => TextValue::optional('author', $author, 255),
            'expires_at' => now()->addMinutes(Upload::LINK_MINUTES),
        ]);
    }
}
