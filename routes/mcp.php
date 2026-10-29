<?php

declare(strict_types=1);

use Laravel\Mcp\Facades\Mcp;
use Mozex\Campaigns\Http\Middleware\AuthenticateAgent;
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
Mcp::web(is_string($path) && trim($path, '/ ') !== '' ? $path : 'mcp/campaigns', CampaignsServer::class)
    ->middleware([AuthenticateAgent::class, ...$middleware])
    ->name('campaigns.mcp');
