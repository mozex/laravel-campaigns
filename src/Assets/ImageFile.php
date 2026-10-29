<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Assets;

use Mozex\Campaigns\CampaignsException;

/**
 * An image to add to the asset library, checked by its content: a JPEG,
 * PNG, GIF or WebP file within the size limit. The type, the stored file's
 * extension and its content type come from the bytes, never from the name
 * it arrived with, and an SVG is never accepted, since it can carry script
 * that would run on the app's own origin.
 */
readonly class ImageFile
{
    protected const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    final public function __construct(
        public string $name,
        public string $bytes,
        public string $mime,
        public string $extension,
        public int $width,
        public int $height,
    ) {}

    /**
     * @throws CampaignsException
     */
    public static function fromBytes(string $bytes, ?string $name = null): static
    {
        $label = static::label($name);
        $max = (int) config('campaigns.assets.max_size');

        if ($bytes === '') {
            throw CampaignsException::invalidImage($label, 'is empty');
        }

        if (strlen($bytes) > $max) {
            throw CampaignsException::invalidImage($label, 'is over '.round($max / 1024 / 1024, 1).' MB');
        }

        $info = @getimagesizefromstring($bytes);
        $type = is_array($info) ? $info[2] : null;

        if (! is_array($info) || ! isset(static::TYPES[$type]) || $info[0] < 1 || $info[1] < 1) {
            throw CampaignsException::invalidImage($label, 'is not a JPEG, PNG, GIF or WebP image');
        }

        [$mime, $extension] = static::TYPES[$type];

        return new static($label, $bytes, $mime, $extension, $info[0], $info[1]);
    }

    /**
     * The file's content address: the same image is always the same file,
     * so uploading it again adds nothing to the library.
     */
    public function hash(): string
    {
        return hash('sha256', $this->bytes);
    }

    /**
     * The name an image is listed under: what it arrived as, cut to the
     * column, or "image" when it came with none.
     */
    protected static function label(?string $name): string
    {
        if ($name === null || ! mb_check_encoding($name, 'UTF-8')) {
            return 'image';
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));

        return $name === '' ? 'image' : mb_substr($name, 0, 255);
    }
}
