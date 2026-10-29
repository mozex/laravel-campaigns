<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

class EmailAddress
{
    /**
     * The longest address a mail server accepts (RFC 5321's 256-character
     * path, less its angle brackets).
     */
    public const MAX_LENGTH = 254;

    /**
     * The form every address is stored and looked up in: trimmed and
     * lower-cased. It doesn't check the address is valid.
     */
    public static function clean(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * The cleaned address, or null when it can't be mailed: empty, too long
     * or malformed. Letters outside ASCII are allowed before the @, in UTF-8
     * only: lower-casing text in another encoding turns its letters into
     * question marks, which would store a different address.
     */
    public static function normalize(?string $address): ?string
    {
        if ($address === null || ! mb_check_encoding($address, 'UTF-8')) {
            return null;
        }

        $address = static::clean($address);

        if ($address === '' || mb_strlen($address) > static::MAX_LENGTH) {
            return null;
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            return null;
        }

        return $address;
    }
}
