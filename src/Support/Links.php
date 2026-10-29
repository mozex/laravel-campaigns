<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

/**
 * The addresses the package puts in emails. They start with campaigns.url,
 * not the request's host or route()'s root, since they are built in queued
 * jobs and must keep working after the app's main domain moves.
 */
class Links
{
    public static function unsubscribe(string $token): string
    {
        return static::to('campaigns.unsubscribe', $token);
    }

    public static function confirm(string $token): string
    {
        return static::to('campaigns.confirm', $token);
    }

    public static function webVersion(string $token): string
    {
        return static::to('campaigns.web-version', $token);
    }

    protected static function to(string $route, string $token): string
    {
        /** @var ?string $base */
        $base = config('campaigns.url') ?: config('app.url');

        return rtrim((string) $base, '/').route($route, ['token' => $token], false);
    }
}
