<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Fetching\Fetcher;
use Mozex\Campaigns\Models\Asset;

/**
 * Fetches an image from an https address into the asset library, through
 * the public-address guard, so an address can't make the server read
 * something on its own network. The library's copy is what emails load.
 */
class AddImageFromUrlAction
{
    public function __construct(
        protected Fetcher $fetcher,
        protected AddImageAction $addImage,
    ) {}

    /**
     * @throws CampaignsException
     */
    public function execute(string $url, ?string $name = null, ?string $author = null): Asset
    {
        $response = $this->fetcher->fetch($url, (int) config('campaigns.assets.max_size'));

        if (! $response->successful()) {
            throw CampaignsException::cannotFetch($url, "answered {$response->status}");
        }

        $path = (string) parse_url($response->url, PHP_URL_PATH);

        return $this->addImage->execute($response->body, $name ?? rawurldecode(basename($path)), $author);
    }
}
