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
     * A locale written one way, so pt_BR, pt-br and PT-BR compare equal.
     */
    public static function normalize(string $locale): string
    {
        return strtolower(str_replace('-', '_', trim($locale)));
    }

    /**
     * A locale's language alone, its first part (pt_BR gives pt).
     */
    public static function language(string $locale): string
    {
        return explode('_', static::normalize($locale))[0];
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
