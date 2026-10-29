<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Assets\AssetLibrary;
use Mozex\Campaigns\Assets\ImageFile;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Asset;

/**
 * Adds one image to the asset library from its bytes: an image a chat app
 * sends inline, or one that arrived through an upload link. The same image
 * added twice is one asset.
 */
class AddImageAction
{
    public function __construct(protected AssetLibrary $library) {}

    /**
     * @throws CampaignsException when it isn't a JPEG, PNG, GIF or WebP image
     *                            within the size limit, or the disk can't serve it
     */
    public function execute(string $bytes, ?string $name = null, ?string $author = null): Asset
    {
        return $this->library->store([ImageFile::fromBytes($bytes, $name)], $author)[0];
    }
}
