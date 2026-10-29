---
title: Mailgun
weight: 2
---

Mailgun delivers the email. The package talks to it three ways: it sends through the `campaigns` mailer ([Installation](./installation.md)), it receives Mailgun's webhooks, and it reads Mailgun's logs, suppression lists and daily counts with a key of its own.

## The sending domain

Add your marketing domain (`news.example.com`) in Mailgun and publish the DNS records Mailgun asks for: SPF, DKIM, and the tracking CNAME, without which opens and clicks can't be tracked. The package decides tracking per email (`o:tracking-opens` and `o:tracking-clicks` on every message), so the domain's own tracking setting never decides for it. Opens are never tracked for subscribers in the countries listed in `campaigns.no_open_tracking_countries` (France by default, whose data protection authority asks for consent to a tracking pixel).

## Webhooks

Point Mailgun's webhooks for the sending domain at:

```
https://your-app.example.com/campaigns/webhooks/mailgun
```

Subscribe the `delivered`, `permanent_fail`, `temporary_fail`, `complained`, `unsubscribed`, `opened` and `clicked` events. `accepted` may be added too; every other event of an email already proves Mailgun took it. Then give the package the account's HTTP webhook signing key, from Mailgun's API Security settings. It's neither the API key nor a sending key:

```dotenv
MAILGUN_WEBHOOK_SIGNING_KEY=your-webhook-signing-key
```

Every request is checked against that key, and the token Mailgun signs is recorded, so a replayed request is refused. The route runs outside your `web` middleware and has no throttle, since Mailgun retries for hours. Until the key is set, the route answers 500 and reports it at most once an hour.

One Mailgun account signs every domain's webhooks with the same key. If several of your apps share an account, a signed request leaked at one would verify at another; give each app a Mailgun subaccount to keep them apart.

## The reading key

The reconciler reads Mailgun's logs (events a webhook missed, sends that ended unclear), an unblock checks whether Mailgun still holds an address, the overview reads the domain's daily counts from the Metrics API, and the suppressions import reads the bounce, complaint and unsubscribe lists. All of that needs an account API key, since a domain's sending key can only send:

```dotenv
CAMPAIGNS_MAILGUN_API_KEY=your-api-key
CAMPAIGNS_MAILGUN_ENDPOINT=api.eu.mailgun.net
```

Where your plan offers roles, the Support role is the narrowest one that reads logs, metrics and suppressions (the Analyst role can't read suppressions). The region comes from `CAMPAIGNS_MAILGUN_ENDPOINT`, else the campaigns mailer's `endpoint`, else Mailgun's US host. Set `campaigns.mailgun.log_days` to the number of days your plan keeps logs (5 by default): nothing older is looked for.

Without the key, Mailgun's logs aren't read: events a webhook missed and sends that ended unclear stay unsettled, `campaigns:reconcile` fails every run (after putting back lost jobs and queueing unprocessed events again), and the overview says so.

## Suppressions you already have

If the domain has sent before, Mailgun already holds addresses that bounced, complained or unsubscribed. Bring them in as blocks and opt-outs before the first campaign:

```bash
php artisan campaigns:import-provider-suppressions news.example.com
php artisan campaigns:import-provider-suppressions news.example.com --force
```

The first run is a dry run that prints what would change. `--email=someone@example.com` looks up one address instead of reading the whole lists. A bounce becomes a `hard_bounce` block, a spam report a `complaint` block, and the unsubscribe list an opt-out, read only for the marketing domain itself. Addresses nobody has subscribed are kept with nothing but the address, so a later import can't mail them.

The package never removes an address from Mailgun's lists. When someone is unblocked, the answer says whether Mailgun still holds them; removing them there is a change you make at Mailgun.
