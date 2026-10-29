<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Mozex\Campaigns\Http\Controllers\AgentFileController;
use Mozex\Campaigns\Http\Middleware\AuthenticateAgent;
use Mozex\Campaigns\Http\Middleware\PageHeaders;
use Mozex\Campaigns\Mcp\CampaignsServer;

/*
 * Loaded by the package, since laravel/mcp loads only the app's own
 * routes/ai.php. Outside the app's web middleware, as laravel/mcp's own
 * routes are: an agent sends a token or an OAuth bearer, never a session.
 */
if (! config('campaigns.mcp.enabled')) {
    return;
}

/** @var list<string> $middleware */
$middleware = array_values(array_filter((array) config('campaigns.mcp.middleware', []), 'is_string'));

$path = config('campaigns.mcp.path');

// An empty path would put the server on the app's home page.
$path = is_string($path) && trim($path, '/ ') !== '' ? trim($path, '/ ') : 'mcp/campaigns';

Mcp::web($path, CampaignsServer::class)
    ->middleware([AuthenticateAgent::class, ...$middleware])
    ->name('campaigns.mcp');

/*
 * The signed links the tools hand an agent: a file sent with curl, and a
 * revision's email to download. curl carries no token, so the signature is
 * the authorization, checked in the controller, which answers every refusal
 * itself; they skip the server's sign-in and the app's middleware for it,
 * and sit under its path so one rule at the app's CDN covers them all.
 */
Route::middleware(PageHeaders::class)->group(function () use ($path): void {
    Route::put("{$path}/uploads/{upload}", [AgentFileController::class, 'upload'])
        ->whereUuid('upload')
        ->name('campaigns.mcp.upload');

    Route::get("{$path}/downloads/{revision}/{file}", [AgentFileController::class, 'download'])
        ->whereUuid('revision')
        ->whereIn('file', AgentFileController::FILES)
        ->name('campaigns.mcp.download');
});
