<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The package's pages carry a secret in their address: nothing may index,
 * cache or frame them, and no link on them may pass the address on.
 */
class PageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Unless the page set its own that already forbids storing (the open
        // pixel's adds no-cache and max-age=0). Every response has a computed
        // Cache-Control, so only its directives tell.
        if (! $response->headers->hasCacheControlDirective('no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
