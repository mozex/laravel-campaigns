<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Support\Str;

/**
 * Free text as an agent's answer shows it. Answers are read as JSON, where
 * a control character takes six characters, so each is shown as a space,
 * and a quote or backslash two, which the cut lengths allow for.
 */
class ShownText
{
    public static function of(?string $value, ?int $length = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/\p{Cc}/u', ' ', $value);

        return $length === null ? $value : Str::limit($value, $length);
    }
}
