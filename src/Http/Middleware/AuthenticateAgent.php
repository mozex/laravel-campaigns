<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Mozex\Campaigns\Mcp\Principal;
use Mozex\Campaigns\Models\ApiToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in the MCP server's caller through the guards in
 * campaigns.mcp.guards, the first that knows them winning.
 *
 * It answers its refusals itself, rather than through Laravel's `auth`
 * middleware and the app's exception handler: an app may render errors on
 * its own `mcp/*` paths its own way, and a client that doesn't ask for JSON
 * would be redirected to a login page. A 401 here always reaches laravel/mcp's
 * WWW-Authenticate header, which tells a client how to sign in, and a
 * principal with neither right gets a 403 before any tool or resource is
 * listed.
 */
class AuthenticateAgent
{
    public function __construct(protected AuthManager $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->headers->get('Authorization');

        foreach ($this->guards() as $guard) {
            // Passport's guard blanks the header when the bearer isn't one
            // of its tokens, which would hide a package token from the next
            // guard.
            if ($authorization !== null) {
                $request->headers->set('Authorization', $authorization);
            }

            $user = $this->user($guard);

            if ($user === null) {
                continue;
            }

            // Tools read the default guard, so it becomes the one that knew them.
            $this->auth->shouldUse($guard);

            if ($user instanceof ApiToken) {
                $user->touchLastUsed();
            }

            if (Principal::for($user)->rights === null) {
                return response()->json(['message' => 'This account may not use the campaigns server: the app grants it through the campaigns.read or campaigns.full gate ability.'], 403);
            }

            return $next($request);
        }

        return response()->json([
            'message' => 'Unauthenticated. Send a campaigns token as a Bearer token in the Authorization header, or sign in.',
        ], 401);
    }

    /**
     * Any guard's user: the package's token guard gives an ApiToken, an
     * app's OAuth guard its own user model.
     */
    protected function user(string $guard): ?Authenticatable
    {
        return $this->auth->guard($guard)->user();
    }

    /**
     * The guards from the config, as a list or a comma-separated string.
     *
     * @return list<string>
     */
    protected function guards(): array
    {
        $guards = config('campaigns.mcp.guards');

        if (is_string($guards)) {
            $guards = explode(',', $guards);
        }

        $names = [];

        foreach (is_array($guards) ? $guards : ['campaigns'] as $guard) {
            $guard = is_string($guard) ? trim($guard) : '';

            if ($guard !== '') {
                $names[] = $guard;
            }
        }

        return $names;
    }
}
