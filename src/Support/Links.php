<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Upload;

/**
 * The addresses the package puts in emails, the MCP server's address the
 * token command prints, and the signed links its tools hand an agent. They
 * start with campaigns.url, not the request's host or route()'s root, since
 * they are built in queued jobs and must keep working after the app's main
 * domain moves. The signed links are signed without the host for the same
 * reason (the controller checks them the same way), so a host the app
 * forces its URLs onto never breaks one.
 */
class Links
{
    /** How long a download link works. */
    public const DOWNLOAD_MINUTES = 60;

    /** How long a preview link works. */
    public const PREVIEW_DAYS = 7;

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
     * The open pixel of the email whose tracking token this is.
     */
    public static function open(string $trackingToken): string
    {
        return static::to('campaigns.open', $trackingToken);
    }

    /**
     * A tracked link: the redirect to the address $key names, for the email
     * whose tracking token this is.
     */
    public static function click(string $key, string $trackingToken): string
    {
        return static::base().route('campaigns.click', ['key' => $key, 'token' => $trackingToken], false);
    }

    /**
     * Whether an address is one of the package's own pages, which a tracked
     * link never wraps and a check never fetches.
     */
    public static function isPackagePage(string $url): bool
    {
        return str_starts_with($url, static::base().'/campaigns/');
    }

    /**
     * The MCP server's address, or null while it is switched off.
     */
    public static function mcpServer(): ?string
    {
        return Route::has('campaigns.mcp') ? static::base().route('campaigns.mcp', [], false) : null;
    }

    /**
     * Where an agent sends an upload's file, until the upload's link expires.
     */
    public static function upload(Upload $upload): string
    {
        return static::signed('campaigns.mcp.upload', $upload->expires_at, ['upload' => $upload->uuid]);
    }

    /**
     * A revision's email to download: email.zip, or email.html in the
     * default language, valid until $expires (downloadsExpireAt()).
     */
    public static function download(Revision $revision, string $file, CarbonInterface $expires): string
    {
        return static::signed('campaigns.mcp.download', $expires, ['revision' => $revision->uuid, 'file' => $file]);
    }

    public static function downloadsExpireAt(): CarbonInterface
    {
        return now()->addMinutes(static::DOWNLOAD_MINUTES)->startOfSecond();
    }

    /**
     * A revision's preview page, valid until $expires (previewExpiresAt()).
     * Its language tabs add a `locale` the signature leaves out.
     */
    public static function preview(Revision $revision, CarbonInterface $expires): string
    {
        return static::signed('campaigns.preview', $expires, ['revision' => $revision->uuid]);
    }

    public static function previewExpiresAt(): CarbonInterface
    {
        return now()->addDays(static::PREVIEW_DAYS)->startOfSecond();
    }

    /**
     * @param  array<string, string>  $parameters
     */
    protected static function signed(string $route, CarbonInterface $expires, array $parameters): string
    {
        return static::base().URL::temporarySignedRoute($route, $expires, $parameters, absolute: false);
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
