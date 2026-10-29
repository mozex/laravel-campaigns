<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Support\ShownText;

/**
 * An image in the asset library as the tools show it. Its address is whole
 * (up to 2,048 characters), its name and who added it cut, so a page of
 * them stays under an answer's limit.
 */
class AssetSummary
{
    public const NAME_LENGTH = 100;

    /**
     * @return array<string, mixed>
     */
    public static function row(Asset $asset): array
    {
        return [
            'id' => $asset->uuid,
            'name' => ShownText::of($asset->name, static::NAME_LENGTH),
            'url' => $asset->url,
            'mime' => ShownText::of($asset->mime),
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'author' => ShownText::of($asset->author, static::NAME_LENGTH),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}
