<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Mozex\Campaigns\Content\Locales;

/**
 * The language a page or email for a subscriber is shown in, and which way
 * its text runs.
 */
class PageLocale
{
    /**
     * Languages written right to left, by their primary subtag.
     */
    protected const RIGHT_TO_LEFT = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /**
     * The first of the given locales the package's strings exist in, else
     * the app's: a language with no strings would show English text laid
     * out right to left. A stored locale must look like a locale (en, pt_BR,
     * zh-Hant) before the translator sees it, which reads it as a path.
     */
    public static function pick(?string ...$locales): string
    {
        foreach ($locales as $locale) {
            if ($locale === null || preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*\z/', $locale) !== 1) {
                continue;
            }

            // pt_BR takes the app's pt strings before the app's own language.
            foreach (array_unique([$locale, Locales::language($locale)]) as $candidate) {
                if (app('translator')->hasForLocale('campaigns::pages.subscribed.heading', $candidate)) {
                    return $candidate;
                }
            }
        }

        return app()->getLocale();
    }

    public static function direction(string $locale): string
    {
        $language = strtolower((string) preg_split('/[_-]/', $locale)[0]);

        return in_array($language, static::RIGHT_TO_LEFT, true) ? 'rtl' : 'ltr';
    }

    /**
     * The locale as an HTML lang attribute (pt_BR becomes pt-BR).
     */
    public static function htmlLang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }
}
