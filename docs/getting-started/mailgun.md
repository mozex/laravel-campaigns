---
title: Mailgun
weight: 2
---

Mailgun delivers the email through the `mailgun` driver, the default (`CAMPAIGNS_DRIVER=mailgun`). The driver talks to Mailgun three ways: it sends through the `campaigns` mailer ([Installation](./installation.md)), it receives Mailgun's webhooks, and it reads Mailgun's logs, suppression lists and daily counts with a key of its own. Its settings live under `campaigns.drivers.mailgun`.

## The sending domain

Add your marketing domain (`news.example.com`) in Mailgun and publish the DNS records Mailgun asks for: SPF and DKIM. Mailgun's tracking CNAME isn't needed: the package tracks opens and clicks itself ([Opens and Clicks](../campaigns/tracking.md)) and turns Mailgun's tracking off on every message (`o:tracking-opens: no` and `o:tracking-clicks: no`), so the domain's own tracking setting never decides for it.

## Webhooks

Point Mailgun's webhooks for the sending domain at:

```
https://your-app.example.com/campaigns/webhooks/mailgun
```

Subscribe the `delivered`, `permanent_fail`, `temporary_fail`, `complained` and `unsubscribed` events. `accepted` may be added too; every other event of an email already proves Mailgun took it. Mailgun's `opened` and `clicked` events are dropped, since it tracks nothing the package sends. Then give the package the account's HTTP webhook signing key, from Mailgun's API Security settings. It's neither the API key nor a sending key:

```dotenv
MAILGUN_WEBHOOK_SIGNING_KEY=your-webhook-signing-key
```

Every request is checked against that key, and the token Mailgun signs is recorded, so a replayed request is refused. The route runs outside your `web` middleware and has no throttle, since Mailgun retries for hours. While Mailgun is the active driver and the key isn't set, the route answers 500 so Mailgun retries once the key is there, reports nothing (anyone can post to it), and `get_overview` notes the missing key (as part of its "reads nothing back" warning when the reading key is missing too), adding that a post was refused in the last day when one was. After a switch to another driver, keep the key set for a few days: the route keeps taking Mailgun's late events about the mail it sent while the key is there. Once it's gone, the route answers 500 while the reading key is still set (Mailgun keeps retrying) and 404 once neither is.

One Mailgun account signs every domain's webhooks with the same key. If several of your apps share an account, a signed request leaked at one would verify at another; give each app a Mailgun subaccount to keep them apart.

## The reading key

The reconciler reads Mailgun's logs (events a webhook missed, sends that ended unclear), an unblock checks whether Mailgun still holds an address, the overview reads the domain's daily counts from the Metrics API, and the suppressions import reads the bounce, complaint and unsubscribe lists. All of that needs an account API key, since a domain's sending key can only send:

```dotenv
CAMPAIGNS_MAILGUN_API_KEY=your-api-key
CAMPAIGNS_MAILGUN_ENDPOINT=api.eu.mailgun.net
```

Where your plan offers roles, the Support role is the narrowest one that reads logs, metrics and suppressions (the Analyst role can't read suppressions). The region comes from `CAMPAIGNS_MAILGUN_ENDPOINT`, else the campaigns mailer's `endpoint`, else Mailgun's US host. Set `CAMPAIGNS_MAILGUN_LOG_DAYS` to the number of days your plan keeps logs (5 by default): nothing older is looked for.

Without the key, nothing fails: the driver reads no logs, suppression lists, counts or usage. Events a webhook missed aren't replayed, a send that ended unclear stays `unknown` (never sent again), an email a killed worker left `sending` becomes `unknown` after 15 minutes so its campaign can complete, `campaigns:import-provider-suppressions` and `unblock_contact`'s check say they need the key, and `get_overview` has one note naming it and what it would add. Set it later and the next `campaigns:reconcile` looks up the unclear sends its logs still reach.

## Your plan's monthly volume

The same key reads how many emails the whole account has processed this month, the number Mailgun bills: every domain and app on the account, and the inbound mail your routes forward. Each Mailgun region reports only its own domains while one plan covers both, so the package reads `api.mailgun.net` and `api.eu.mailgun.net` and adds them up, whatever `CAMPAIGNS_MAILGUN_ENDPOINT` says. The month is the calendar month in UTC, which is when Mailgun's billing cycle starts again.

Tell the package how many emails your plan includes, and agents are warned when a campaign would take the account past it:

```dotenv
CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED=50000
```

`get_overview` shows the count as `usage`, and `start_campaign`, `resume_campaign` and `speed_up_campaign` show it again in their answer. When this month's count plus the emails still to send, this campaign's and the app's other open campaigns', passes the plan, their `warnings` say by how many. Your other apps' campaigns on the same account aren't counted until they send. Mailgun bills those as overage rather than refusing them, so nothing is stopped. A region that can't be read leaves the other's count with a note saying so.

## Suppressions you already have

If the domain has sent before, Mailgun already holds addresses that bounced, complained or unsubscribed. Bring them in as blocks and opt-outs before the first campaign:

```bash
php artisan campaigns:import-provider-suppressions news.example.com
php artisan campaigns:import-provider-suppressions news.example.com --force
```

The first run is a dry run that prints what would change. `--email=someone@example.com` looks up one address instead of reading the whole lists. A bounce becomes a `hard_bounce` block, a spam report a `complaint` block, and the unsubscribe list an opt-out, read only for the marketing domain itself. Addresses nobody has subscribed are kept with nothing but the address, so a later import can't mail them.

The package never removes an address from Mailgun's lists. When someone is unblocked, the answer says whether Mailgun still holds them; removing them there is a change you make at Mailgun.
