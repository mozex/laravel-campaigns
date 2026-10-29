<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

/**
 * The keys agents name templates, campaigns and saved audiences by.
 */
class Keys
{
    public const MAX_LENGTH = 125;

    public static function isValid(string $key): bool
    {
        return strlen($key) <= static::MAX_LENGTH
            && preg_match('/^[a-z0-9][a-z0-9._-]*\z/', $key) === 1;
    }
}
