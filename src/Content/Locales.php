<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

class Locales
{
    /**
     * The language every revision holds, and the one a recipient gets when
     * a revision lacks theirs: campaigns.locale, which the service provider
     * fills with the app's locale before any request can change it.
     */
    public static function default(): string
    {
        return (string) (config('campaigns.locale') ?: config('app.locale'));
    }

    /**
     * Whether a value looks like a locale (en, pt_BR, zh-Hant), as stored
     * locales must before the translator reads one as part of a path.
     */
    public static function isValid(string $locale): bool
    {
        return strlen($locale) <= 35
            && preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*\z/', $locale) === 1;
    }
}
