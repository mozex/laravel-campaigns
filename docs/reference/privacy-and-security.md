---
title: Privacy and Security
weight: 6
---

## What's kept

- **Subscribers:** the address, and the name, language, timezone, country and custom fields your list sources or imports give. An address is stored lower-cased and compared byte for byte.
- **Consent records:** every subscribe, opt-out, block, unblock and confirmation, with its source, basis and the notice version the person saw. A change the person made themselves (a sign-up form, the unsubscribe page) also keeps their IP address and browser, for answering a complaint. A change an agent made never does: those would be the agent's machine.
- **Campaign messages:** one row per recipient with the address it went to, so a report and an old email's unsubscribe link keep working.
- **Provider events:** what Mailgun reported (delivered, bounced, opened, clicked), without the address or the IP. A clicked link keeps no part that holds an address or a link token. Events go after `campaigns.event_retention_days` (180), once their campaign's report is kept.
- **Tokens:** MCP tokens and the tokens in an email's links are stored as SHA-256 hashes; the raw value exists only in the email or the command output that carries it.

## Forgetting someone

Deleting a record behind a dynamic list forgets its subscriber on its own. For anyone else (a deletion request from a newsletter subscriber, say), call the action:

```php
use Mozex\Campaigns\Actions\ForgetSubscriberAction;
use Mozex\Campaigns\Models\Subscriber;

if ($subscriber = Subscriber::findByEmail($email)) {
    app(ForgetSubscriberAction::class)->execute($subscriber);
}
```

Their lists, details, IP addresses, browsers and the addresses on their sent mail go, and mail still waiting is never sent. The consent records and the campaigns' numbers stay. A blocked or opted-out address keeps its row, stripped to the address and that state, so a later import of the same address can't mail it again; any other address is deleted.

## What your error tracker should never see

Error trackers record request paths, bodies and outgoing requests. Some of the package's carry secrets or personal data, so redact these in yours:

| Where | Why |
|---|---|
| paths under `/campaigns/u/`, `/campaigns/c/` and `/campaigns/v/` | each holds a link token that unsubscribes, confirms or shows one person's email |
| the `signature` query of `/campaigns/preview/...` and of the links under the MCP path (`/mcp/campaigns/uploads/...`, `/mcp/campaigns/downloads/...`) | the signature is the link's only authorization |
| the body of `PUT /mcp/campaigns/uploads/...` | an unsent email or a list of contacts |
| the `event-data` field of `POST /campaigns/webhooks/mailgun` | the recipient's address and IP, and a clicked link that may hold a token |
| the arguments of MCP tool calls (`POST /mcp/campaigns`) | addresses an agent adds, blocks or looks up |
| outgoing requests to `api.*.mailgun.net/v3/<domain>/bounces`, `/complaints` and `/unsubscribes`, path and query | one address's lookup puts the address in the path, and the lists' next pages carry one in the query |
| the `Authorization` header | an MCP token or an OAuth bearer |

The package keeps addresses out of its own exception messages and reports, and never chains an HTTP client's exception whose message quotes a request URL. A database error is passed on with its SQLSTATE and without the SQL, whose values hold addresses and visitors' IP addresses. A deadlock while a list syncs or an import runs inside your own transaction reaches your app as a `DeadlockException` of its own with code 40001 and nothing chained, so Laravel still retries the transaction.

## Pages that render agents' HTML

An email an agent uploads is shown on your app's own domain in three places, each locked down so nothing in it can run:

- **The web version** (`/campaigns/v/...`) answers with a `sandbox` Content-Security-Policy: no scripts, no same-origin access, no forms.
- **The preview page** shows the email inside `<iframe sandbox srcdoc>` frames under a policy that allows no script, filled only with made-up details, never a real subscriber's.
- **Downloads** are always attachments (`Content-Disposition: attachment`, `nosniff`), never pages.

SVG images are refused, since an SVG can carry script, and an image's type comes from its bytes, never its name.

## Requests the server makes

The pre-send checks fetch every link in an email, and an agent can add an image from an address. Every such request goes through one guard: each hop, redirects included, must name a host whose every address is public, the connection is pinned to the address that was checked, and no proxy is used. An address that points into your own network, such as `127.0.0.1` or a cloud metadata service, is refused, and the refusal never names the private address. A host name written in another script (`bücher.example`) is fetched only when PHP has the `intl` extension, which turns it into the form DNS reads; without it, such an address is refused until it is written in its `xn--` form.

## Pages and links

- Opening a link changes nothing; only a button press (a POST) does, since link scanners open every link in an email. That holds for the double opt-in confirmation too.
- The one-click unsubscribe (RFC 8058) answers a plain 200 to a POST with no cookies and no CSRF token, as mail apps send it. The pages set no cookie and use no session.
- The pages are throttled per visitor through your app's `throttle` middleware, and their buttons per link, since mail apps post many people's one-clicks from a few servers.
