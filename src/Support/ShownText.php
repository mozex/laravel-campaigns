<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Support\Str;

/**
 * Free text as an agent's answer shows it. Answers are read as JSON, where
 * a control character takes six characters, and so do the line and
 * paragraph separators (U+2028, U+2029), which JSON escapes even with
 * Unicode left unescaped, so each is shown as a space; a quote or backslash
 * takes two, which the cut lengths allow for.
 */
class ShownText
{
    public static function of(?string $value, ?int $length = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/[\p{Cc}\x{2028}\x{2029}]/u', ' ', $value);

        return $length === null ? $value : Str::limit($value, $length);
    }
}
