---
title: Connecting Agents
weight: 5
---

Agents reach the package through an MCP server it adds to your app, at `/mcp/campaigns` (`CAMPAIGNS_MCP_PATH` moves it; `CAMPAIGNS_MCP_ENABLED=false` switches it off). Any MCP client can use it. It answers only to a token the package issued or to a person your app signs in and allows: tokens work with no setup, and OAuth sign-in for chat apps is an [optional extra](./optional-extras.md).

## Tokens

A token suits a client on a machine you trust that sends a fixed header, such as Claude Code, Cursor or Codex:

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

Any other client takes the same address and an `Authorization: Bearer <token>` header in its own MCP settings.

## Signing people in with OAuth

Chat apps such as claude.ai, Claude's desktop app and ChatGPT can't send a fixed header; they sign a person in through OAuth. Laravel's MCP package gives your app what they need on top of Laravel Passport: install Passport, call `Mcp::oauthRoutes()` for the discovery documents and client registration, and set up the consent page, as [Laravel's MCP documentation](https://laravel.com/docs/mcp) describes. Then add Passport's guard after the package's in `config/campaigns.php`. Another OAuth server works the same way, as long as its guard signs a bearer token in:

```php
'mcp' => [
    // ...
    'guards' => ['campaigns', 'api'],
],
```

The guards are tried in order. Passport's guard blanks the `Authorization` header when the bearer isn't one of its tokens, so the package puts the header back before each guard.

Signing in isn't enough: your user model decides what each person may do, by implementing `HasCampaignRights`:

```php
use Mozex\Campaigns\Contracts\HasCampaignRights;
use Mozex\Campaigns\Enums\Rights;

class User extends Authenticatable implements HasCampaignRights
{
    public function campaignRights(): ?Rights
    {
        return match (true) {
            $this->isBanned() => null,
            $this->isAdmin() => Rights::Full,
            $this->isStaff() => Rights::Read,
            default => null,
        };
    }
}
```

`Rights::Full` reaches every tool, `Rights::Read` only the ones that change nothing. Someone whose model answers `null`, or doesn't implement the contract, is refused with a 403 before any tool is listed. The method is read once a request. A request with no valid token or sign-in gets a 401 whose `WWW-Authenticate` header points at your OAuth metadata once your app serves it, which is how a client knows to start the sign-in.

The package asks Laravel's gate nothing, so a `Gate::before()` that lets your admins do anything, or a permissions package's super-admin role, opens nothing here unless your method says so. Calling `$this->can()` inside the method runs your gate as usual, those hooks included: that's your choice to make.

The server's route runs none of your app's `web` or `api` middleware, only what you list in `campaigns.mcp.middleware` (below), so the method is the place for your account checks, as the ban above: a chat app connected before the ban can keep refreshing its token.

Once someone signs in through OAuth, the guard that knew them (`api`) becomes the request's default guard, since the tools read the default. A permissions package that picks its guard from that default then looks for an `api` permission. With spatie/laravel-permission, whose permissions usually belong to the `web` guard, name the guard in the check:

```php
public function campaignRights(): ?Rights
{
    return match (true) {
        $this->isBanned() => null,
        $this->can('manage campaigns', 'web') => Rights::Full,
        $this->can('read campaigns', 'web') => Rights::Read,
        default => null,
    };
}
```

Under a token, the request's user is the token (`Mozex\Campaigns\Models\ApiToken`), not one of your users. The package never asks your gate anything, and nothing of yours that an MCP call runs may ask it about the request's user either: your audiences, resources and the listeners on the package's events. A gate check about the request's user (`Gate::allows()`, `@can`) runs every gate callback against the token, and one written for your own user model throws there. spatie/laravel-permission's callback does, and so does Filament Shield's super-admin callback, since a gate's `before` and `after` callbacks run for every ability. `$request->user()->can()` throws too, since a token has no `can()`. To check a user you already hold, ask `Gate::forUser($user)`.

## What agents can do

Each tool is either read-only or a write, and the rights decide which an agent sees. Eight writes carry Claude Code's approval flag, so Claude Code asks the person before every call even when it runs without prompts: starting, resuming and speeding up a campaign, sending a test email, archiving a list or a template, unblocking an address, and running a contacts import. Pausing and cancelling never ask, so stopping is never held up; the server's guidance still tells agents to cancel only when the person decides, since a cancelled campaign can't be resumed. [The MCP Server](../agents/mcp-server.md) lists every tool.

## Your own instructions

The server tells agents how to work with it in its instructions, about 1,950 characters, and a full guide they read on demand. Add your own rules after the package's:

```php
'mcp' => [
    // ...
    'instructions' => 'Only start campaigns on weekdays. Our brand guide is the "brand" resource.',
],
```

Both together must stay under 4,096 characters, which is what Claude Code keeps, so you have room for about 2,100. The server refuses to start past that, naming the setting.

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

Agents call the MCP path from servers, not browsers, and upload files under it. [Installation](./installation.md#let-services-through-to-your-app) lists what a CDN, firewall or proxy in front of the app must let through, the OAuth paths included, and the request size uploads need.
