<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Support\Facades\Route;

/**
 * The addresses the package puts in emails, and the MCP server's address
 * the token command prints. They start with campaigns.url, not the
 * request's host or route()'s root, since they are built in queued jobs and
 * must keep working after the app's main domain moves.
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

    /**
     * The MCP server's address, or null while it is switched off.
     */
    public static function mcpServer(): ?string
    {
        return Route::has('campaigns.mcp') ? static::base().route('campaigns.mcp', [], false) : null;
    }

    protected static function to(string $route, string $token): string
    {
        return static::base().route($route, ['token' => $token], false);
    }

    protected static function base(): string
    {
        /** @var ?string $base */
        $base = config('campaigns.url') ?: config('app.url');

        return rtrim((string) $base, '/');
    }
}
