<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Mozex\Campaigns\CampaignsException;

/**
 * A short piece of text an agent gives (a name, a note), trimmed and checked
 * before it reaches a column, whose error would otherwise carry the SQL.
 */
class TextValue
{
    /**
     * @throws CampaignsException
     */
    public static function required(string $field, string $value, int $max): string
    {
        $value = static::optional($field, $value, $max);

        if ($value === null) {
            throw CampaignsException::invalidValue($field, 'is empty');
        }

        return $value;
    }

    /**
     * Null for nothing, or for a value that is only whitespace.
     *
     * @throws CampaignsException
     */
    public static function optional(string $field, ?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw CampaignsException::invalidValue($field, "isn't UTF-8 text");
        }

        $value = trim($value);

        if (mb_strlen($value) > $max) {
            throw CampaignsException::invalidValue($field, "may be at most {$max} characters long");
        }

        return $value === '' ? null : $value;
    }
}
