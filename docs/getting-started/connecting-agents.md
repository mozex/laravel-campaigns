---
title: Connecting Agents
weight: 4
---

Agents reach the package through an MCP server it adds to your app, at `/mcp/campaigns` (`CAMPAIGNS_MCP_PATH` moves it; `CAMPAIGNS_MCP_ENABLED=false` switches it off). It answers only to a token the package issued or to a person your app signs in and allows.

## Tokens

A token suits a machine you trust, such as your laptop's Claude Code or Codex:

```bash
php artisan campaigns:token create laptop
php artisan campaigns:token create reporting --read-only
php artisan campaigns:token list
php artisan campaigns:token revoke laptop
```

The token is printed once and stored only as a hash. A `--read-only` token sees only the tools that change nothing; any other has full rights. Revoking deletes it, and its name is free again.

Claude Code takes it as a header:

```bash
claude mcp add --transport http campaigns https://your-app.example.com/mcp/campaigns \
  --header "Authorization: Bearer <token>"
```

Codex reads it from an environment variable, in `~/.codex/config.toml`:

```toml
[mcp_servers.campaigns]
url = "https://your-app.example.com/mcp/campaigns"
bearer_token_env_var = "CAMPAIGNS_TOKEN"
```

## Signing people in with OAuth

Chat apps such as claude.ai and ChatGPT can't send a fixed header; they sign a person in through OAuth. Laravel's MCP package gives your app what they need on top of Laravel Passport: install Passport, call `Mcp::oauthRoutes()` for the discovery documents and client registration, and set up the consent page, as [Laravel's MCP documentation](https://laravel.com/docs/13.x/mcp) describes. Then add Passport's guard after the package's in `config/campaigns.php`:

```php
'mcp' => [
    // ...
    'guards' => ['campaigns', 'api'],
],
```

The guards are tried in order. Passport's guard blanks the `Authorization` header when the bearer isn't one of its tokens, so the package puts the header back before each guard.

Signing in isn't enough: a person needs one of two gate abilities, which your app defines and the package never does:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('campaigns.read', fn (User $user): bool => $user->isStaff());
Gate::define('campaigns.full', fn (User $user): bool => $user->isAdmin());
```

`campaigns.full` reaches every tool, `campaigns.read` only the ones that change nothing. Someone signed in with neither is refused with a 403 before any tool is listed. A request with no valid token or sign-in gets a 401 whose `WWW-Authenticate` header points at your OAuth metadata once your app serves it, which is how a client knows to start the sign-in.

Under a token, the request's user is the token (`Mozex\Campaigns\Models\ApiToken`), not one of your users. The package never asks your gate about it, but middleware you add to the route might: a `Gate::before()` callback typed for your own user model would throw there.

## What agents can do

Each tool is either read-only or a write, and the rights decide which an agent sees. Eight writes carry Claude Code's approval flag, so Claude Code asks the person before every call even when it runs without prompts: starting, resuming and speeding up a campaign, sending a test email, archiving a list or a template, unblocking an address, and running a contacts import. Pausing and cancelling never ask, so stopping is never held up; the server's guidance still tells agents to cancel only when the person decides, since a cancelled campaign can't be resumed. [The MCP Server](../agents/mcp-server.md) lists every tool.

## Your own instructions

The server tells agents how to work with it in its instructions, about 1,700 characters, and a full guide they read on demand. Add your own rules after the package's:

```php
'mcp' => [
    // ...
    'instructions' => 'Only start campaigns on weekdays. Our brand guide is the "brand" resource.',
],
```

Both together must stay under 4,096 characters, which is what Claude Code keeps, so you have room for about 2,300. The server refuses to start past that, naming the setting.

## Throttling

`campaigns.mcp.middleware` runs after sign-in, the place for a throttle. Key it by a limiter of your own: Laravel's `throttle` keys a signed-in user by id, and token 1 would share a bucket with your user 1.

```php
RateLimiter::for('campaigns-mcp', fn (Request $request): Limit => Limit::perMinute(120)
    ->by($request->user()::class.':'.$request->user()->getAuthIdentifier()));
```

```php
'middleware' => ['throttle:campaigns-mcp'],
```

## Proxies, firewalls and request size

Agents and Mailgun call your app from servers, not browsers. If a CDN or firewall in front of the app runs a browser or bot check, turn it off for these paths, and keep them out of any cache:

- the MCP path and everything under it (`/mcp/campaigns`, its upload and download links);
- `/campaigns/` (the unsubscribe, confirmation, web version and preview pages, and the Mailgun webhook);
- for OAuth, `/oauth/` and `/.well-known/oauth-`.

Files go up to `/mcp/campaigns/uploads/...` with curl, 25 MB at most by default (`campaigns.uploads.max_size`). Laravel holds every request's body to PHP's `post_max_size`, so raise that to match, and keep both under what your web server and proxy accept in one request. The upload tool tells agents the smaller of the limits.
