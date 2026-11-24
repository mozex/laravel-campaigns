<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * Where the package's docs explain a setting an answer says is missing: the
 * page in the repository's docs/ at the installed version's tag (main for
 * an untagged install), so it describes the code that answers.
 */
class Docs
{
    public const REPOSITORY = 'https://github.com/mozex/laravel-campaigns';

    /**
     * The pages answers point to, under docs/ (a built-in driver's is
     * DriverManager::page()'s).
     */
    public const AGENTS = 'getting-started/connecting-agents.md';

    public const CHECKS = 'campaigns/emails.md';

    public const FEEDBACK = 'campaigns/feedback.md';

    /**
     * A page's address.
     */
    public static function url(string $page): string
    {
        return static::REPOSITORY.'/blob/'.static::ref().'/docs/'.$page;
    }

    /**
     * " See <address>." for the end of a note, or '' without a page.
     */
    public static function see(?string $page): string
    {
        return $page === null ? '' : ' See '.static::url($page).'.';
    }

    /**
     * The installed version's tag, or main when the install isn't one.
     */
    protected static function ref(): string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('mozex/laravel-campaigns');
        } catch (Throwable) {
            $version = null;
        }

        return is_string($version) && preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1 ? $version : 'main';
    }
}
