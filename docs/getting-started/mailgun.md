---
title: Mailgun
weight: 2
---

The `mailgun` driver is the default (`CAMPAIGNS_DRIVER=mailgun`). It sends through the `campaigns` mailer on Mailgun's API and hears back through Mailgun's webhooks; both are required. With an account API key as well, it reads Mailgun's logs, suppression lists, daily counts and usage, an [optional extra](./optional-extras.md). Its settings live under `campaigns.drivers.mailgun`.

## The sending domain

Add your sending domain (`news.example.com`) in Mailgun and publish the records it lists (SPF, DKIM, MX and a tracking CNAME), as [Mailgun's guide to domains](https://documentation.mailgun.com/docs/mailgun/user-manual/domains/) describes. Pick the region with care: a domain stays in the US or EU region it was added to, and the mailer and the API host follow it.

- **SPF and DKIM** sign and authorise the mail. Add a DMARC record of your own too ([Installation](./installation.md#choose-the-sending-domain)); Mailgun's list doesn't include one.
- **MX records** make Mailgun receive the mail sent to the domain. If another mail server already receives mail there, keep its records instead of Mailgun's. Replies go to `CAMPAIGNS_REPLY_TO`, wherever that is.
- **The tracking CNAME** isn't needed: the package tracks opens and clicks itself ([Opens and Clicks](../campaigns/tracking.md)) and turns Mailgun's tracking off on every message (`o:tracking-opens` and `o:tracking-clicks` set to `no`), whatever the domain's own tracking setting says.

Two things Mailgun's guide doesn't make plain:

- **Which domain signs.** When your root domain (`example.com`) is already on the same Mailgun account, Mailgun can set up a subdomain to sign with the root's DKIM key, and your newsletter's reputation then rides on your main domain's signature. Look at the DKIM record Mailgun gives you: its name should end in `._domainkey.news.example.com`. If it ends in `._domainkey.example.com`, the subdomain isn't its own DKIM authority. Make it one: through a DKIM authority choice when you add the domain, if Mailgun's form offers one, or afterwards through the API's [`dkim_authority`](https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/domain-keys/put-v3-domains--name--dkim-authority) setting (`self` set to true). Then publish the new record it gives.
- **When a key starts signing.** Mailgun checks a DKIM key's record in DNS before it signs with it, so a new or rotated key stays inactive until its record resolves. Publish the record first, then verify the domain or activate the key in Mailgun.

## The mailer

Add the `campaigns` mailer to `config/mail.php`. It must use Mailgun's API (`'scheme' => 'api'`): the SMTP transport would put Mailgun's options into the email as headers.

```php
'mailers' => [
    // ...

    'campaigns' => [
        'transport' => 'mailgun',
        'scheme' => 'api',
        'domain' => env('CAMPAIGNS_MAILGUN_DOMAIN'),
        'secret' => env('CAMPAIGNS_MAILGUN_SECRET'),
        'endpoint' => env('CAMPAIGNS_MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],
],
```

```dotenv
CAMPAIGNS_MAILGUN_DOMAIN=news.example.com
CAMPAIGNS_MAILGUN_SECRET=your-sending-key
CAMPAIGNS_MAILGUN_ENDPOINT=api.mailgun.net
```

`secret` is a sending key for that domain. The endpoint is `api.mailgun.net` for a domain in the US region and `api.eu.mailgun.net` for one in the EU ([Mailgun's regions](https://documentation.mailgun.com/docs/mailgun/api-reference/api-overview)). When the mailer leaves out `client` options, the driver gives it a 10-second idle timeout and 30 seconds in all, so a stuck request ends before the job sending it does.

## Webhooks

Webhooks are how bounces, spam reports and unsubscribes come back, so add them before your first campaign. In Mailgun's webhooks page, for the sending domain, point these events at your app:

```
https://your-app.example.com/campaigns/webhooks/mailgun
```

Subscribe `delivered`, `permanent_fail`, `temporary_fail`, `complained` and `unsubscribed`. `accepted` may be added too; every other event of an email already proves Mailgun took it. Leave out `opened` and `clicked`: Mailgun tracks nothing the package sends, and the driver drops them. [Mailgun's webhook guide](https://documentation.mailgun.com/docs/mailgun/user-manual/webhooks/webhooks) has the steps. Add them a few minutes before the email you first want them to report: Mailgun can take a moment to start calling a new webhook, and an event it never sent is lost unless the [reading key](#the-reading-key) replays it.

Then give the package the account's HTTP webhook signing key, from the API Security page of Mailgun's dashboard. It's neither the API key nor a sending key:

```dotenv
MAILGUN_WEBHOOK_SIGNING_KEY=your-webhook-signing-key
```

Every request is checked against that key, and the token Mailgun signs is recorded, so a replayed request is refused. The route runs outside your `web` middleware and has no throttle, since Mailgun retries for hours. While Mailgun is the active driver and the key isn't set, the route answers 500 so Mailgun retries once the key is there, reports nothing (anyone can post to it), and `get_overview` notes the missing key (as part of its "reads nothing back" warning when the reading key is missing too), adding that a post was refused in the last day when one was. After a switch to another driver, keep the key set for a few days: the route keeps taking Mailgun's late events about the mail it sent while the key is there. Once it's gone, the route answers 500 while the reading key is still set (Mailgun keeps retrying) and 404 once neither is.

One Mailgun account signs every domain's webhooks with the same key. If several of your apps share an account, a signed request leaked at one would verify at another; give each app a Mailgun subaccount to keep them apart.

## The reading key

An optional extra. The reconciler reads Mailgun's logs (events a webhook missed, sends that ended unclear), an unblock checks whether Mailgun still holds an address, the overview reads the domain's daily counts from the Metrics API, and the suppressions import reads the bounce, complaint and unsubscribe lists. All of that needs an account API key, since a domain's sending key can only send:

```dotenv
CAMPAIGNS_MAILGUN_API_KEY=your-api-key
```

Where your plan offers [key roles](https://documentation.mailgun.com/docs/mailgun/user-manual/api-key-mgmt/rbac-mgmt), Support is the narrowest one that reads logs, metrics and suppressions (Analyst can't read suppressions); a plan without roles gives only full keys. The key reads through `CAMPAIGNS_MAILGUN_ENDPOINT`, else the campaigns mailer's `endpoint`, else Mailgun's US host. Set `CAMPAIGNS_MAILGUN_LOG_DAYS` to how many days your plan keeps logs: 5 by default, as Foundation keeps; Free and Basic keep 1 and Scale 30 ([Mailgun's plans](https://www.mailgun.com/pricing/)). Nothing older is looked for.

Without the key, nothing fails: the driver reads no logs, suppression lists, counts or usage. Events a webhook missed aren't replayed, a send that ended unclear stays `unknown` (never sent again), an email a killed worker left `sending` becomes `unknown` after 15 minutes so its campaign can complete, `campaigns:import-provider-suppressions` and `unblock_contact`'s check say they need the key, and `get_overview` has one note naming it and what it would add. Set it later and the next `campaigns:reconcile` looks up the unclear sends its logs still reach.

## Your plan's monthly volume

An optional extra, read with the same key: how many emails the whole account has processed this month, the number Mailgun bills. That's every domain and app on the account, and the inbound mail your routes forward. Each Mailgun region reports only its own domains while one plan covers both, so the package reads `api.mailgun.net` and `api.eu.mailgun.net` and adds them up, whatever `CAMPAIGNS_MAILGUN_ENDPOINT` says. The month is the calendar month in UTC, which is when Mailgun's billing cycle starts again.

Tell the package how many emails your plan includes, and agents are warned when a campaign would take the account past it:

```dotenv
CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED=50000
```

`get_overview` shows the count as `usage`, and `start_campaign`, `resume_campaign` and `speed_up_campaign` show it again in their answer. When this month's count plus the emails still to send, this campaign's and the app's other open campaigns', passes the plan, their `warnings` say by how many. Your other apps' campaigns on the same account aren't counted until they send. Mailgun bills those as overage rather than refusing them, so nothing is stopped. A region that can't be read leaves the other's count with a note saying so.

## Suppressions you already have

If the domain has sent before, Mailgun already holds addresses that bounced, complained or unsubscribed. With the reading key, bring them in as blocks and opt-outs before the first campaign:

```bash
php artisan campaigns:import-provider-suppressions news.example.com
php artisan campaigns:import-provider-suppressions news.example.com --force
```

The first run is a dry run that prints what would change. `--email=someone@example.com` looks up one address instead of reading the whole lists. A bounce becomes a `hard_bounce` block, a spam report a `complaint` block, and the unsubscribe list an opt-out, read only for the sending domain itself. Addresses nobody has subscribed are kept with nothing but the address, so a later import can't mail them.

Run it for every domain on the account that has mailed the same people, your transactional domain included: a bounce there is a fact about the address, whichever domain found it. Their unsubscribe lists are left alone, since unsubscribing from receipts isn't unsubscribing from your newsletter.

The package never removes an address from Mailgun's lists. When someone is unblocked, the answer says whether Mailgun still holds them; removing them there is a change you make at Mailgun.
