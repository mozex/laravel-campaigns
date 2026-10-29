<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What an upload link is for: the tool that asked for it claims only an
 * upload of its own purpose.
 */
enum UploadPurpose: string
{
    /** One HTML file, or a zip of an email with its translations and images. */
    case Email = 'email';

    case Image = 'image';

    /** A list of contacts to import. */
    case Contacts = 'contacts';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'an email',
            self::Image => 'an image',
            self::Contacts => 'a list of contacts',
        };
    }
}
