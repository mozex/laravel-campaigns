<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Assets;

use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\TextValue;

/**
 * The app's images for emails, on campaigns.assets.disk. A file is named
 * after its content, so its address is known before it is stored (an
 * upload's email is checked against the final addresses first) and the
 * same image uploaded again is the asset already there. Nothing is ever
 * deleted: delivered mail keeps loading the images.
 */
class AssetLibrary
{
    public function disk(): string
    {
        return (string) config('campaigns.assets.disk');
    }

    public function path(ImageFile $image): string
    {
        return trim((string) config('campaigns.assets.path'), '/').'/'.$image->hash().'.'.$image->extension;
    }

    /**
     * The image's public address once stored.
     *
     * @throws CampaignsException when the disk's addresses aren't https
     */
    public function url(ImageFile $image): string
    {
        $url = Storage::disk($this->disk())->url($this->path($image));

        if (! str_starts_with(strtolower($url), 'https://')) {
            throw CampaignsException::assetDiskNotHttps($this->disk());
        }

        return $url;
    }

    /**
     * Stores each image the library doesn't hold yet and returns the
     * library's asset for every one, in order.
     *
     * @param  list<ImageFile>  $images
     * @return list<Asset>
     *
     * @throws CampaignsException
     */
    public function store(array $images, ?string $author = null): array
    {
        $author = TextValue::optional('author', $author, 255);
        $disk = $this->disk();
        $assets = [];

        foreach ($images as $image) {
            $path = $this->path($image);
            $url = $this->url($image);

            $existing = Asset::query()->where('disk', $disk)->where('path', $path)->first();

            if ($existing !== null) {
                $assets[] = $existing;

                continue;
            }

            // The same bytes always land at the same path, so a file left by
            // an earlier attempt that never got its row is simply rewritten.
            if (! Storage::disk($disk)->put($path, $image->bytes)) {
                throw CampaignsException::assetNotStored($image->name);
            }

            $assets[] = Rows::firstOrCreate(Asset::query(), ['disk' => $disk, 'path' => $path], [
                'url' => $url,
                'name' => $image->name,
                'mime' => $image->mime,
                'size' => strlen($image->bytes),
                'width' => $image->width,
                'height' => $image->height,
                'author' => $author,
            ]);
        }

        return $assets;
    }
}
