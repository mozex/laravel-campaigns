---
title: Installation
weight: 1
---

Setting up is the steps below, in order, and each one is required wherever it applies to your app (a step that depends on your setup, such as a CDN or an error tracker, says so): skip one and it shows up later as bounces nobody hears about, images that don't load, or campaigns that never send. Sending stays off until the last step, so nothing reaches an inbox while you work. Once your first test email arrives, [Optional Extras](./optional-extras.md) lists what you can add, each one off until you set it up.

1. [Install the package](#install-the-package)
2. [Choose the sending domain](#choose-the-sending-domain)
3. [Set up the driver](#set-up-the-driver)
4. [Set the sender](#set-the-sender)
5. [Store email images](#store-email-images)
6. [Run a queue worker](#run-a-queue-worker)
7. [Run the scheduler](#run-the-scheduler)
8. [Let services through to your app](#let-services-through-to-your-app)
9. [Keep tokens out of your error tracker](#keep-tokens-out-of-your-error-tracker)
10. [Fill a list](#fill-a-list)
11. [Connect an agent](#connect-an-agent)
12. [Turn sending on and send a test](#turn-sending-on-and-send-a-test)

## Install the package

```bash
composer require mozex/laravel-campaigns
php artisan campaigns:install
```

The install command publishes `config/campaigns.php` and the migrations. If your app keys polymorphic relations by UUID or ULID (`Schema::morphUsingUuids()` or `morphUsingUlids()`), call that in a service provider before you migrate: the link from a subscriber to one of your records follows it. Then run the migrations:

```bash
php artisan migrate
```

The tables work on MySQL, MariaDB, PostgreSQL and SQLite. SQL Server isn't supported, since its unique indexes allow only one `NULL`.

A later version can add a migration. After updating, run `php artisan campaigns:install` again and migrate: it publishes only the migrations missing from `database/migrations` and leaves your config file as it is. If you keep migrations in another folder, copy just the new stub from the package's `database/migrations` instead, named the way Laravel runs it (`2026_12_01_000000_upgrade_campaigns_tables_to_0_2.php`, with a timestamp after your first one), since the install command can't see that folder and would publish the first migration a second time. The same happens after `php artisan schema:dump --prune` removed the first migration: delete the copy of it the install command publishes before you migrate.

If your app uses [Laravel Boost](https://laravel.com/docs/boost), give your coding agent the package's guideline and skill too. Run `php artisan boost:update` in a terminal and pick `mozex/laravel-campaigns` when it offers newly installed packages, or add the package's name to the `packages` list in `boost.json` and run `boost:update`. The offer only appears when the command runs interactively, so a `boost:update` in a Composer script never adds a new package.

## Choose the sending domain

Every campaign goes out from one sending domain, and mailbox providers judge your mail by it. Use a domain kept for marketing, usually a subdomain of your main one, such as `news.example.com` for `example.com`. A bad week of campaigns then never sends your password resets and receipts to spam.

Your main domain, or the one your app already sends from, works too, at a cost:

- Your transactional mail and your campaigns share one reputation.
- They share suppressions too. When a campaign's reader complains or their address bounces, the service holds back your later mail to them, password resets and receipts included: Mailgun keeps these lists per domain, and SES per account and region (a configuration set can keep its own, [Amazon SES](./ses.md#suppressions-and-daily-counts)).
- The sending service's events for that domain can include your transactional mail's. The package drops events about mail it didn't send, without queueing anything, but each still reaches your app as a request, and a driver that reads the service's logs reads through them too. Mailgun reports every event of a domain this way. With Amazon SES, the rule you write matches one configuration set, so give campaigns a set of their own.
- Gmail's spam rate and the provider's daily counts, which agents read for the sending domain, then cover that domain's other mail as well.

Before the first campaign, the domain needs three DNS records. Gmail and Yahoo require all three from bulk senders ([Google's guidelines](https://support.google.com/a/answer/81126), [Yahoo's](https://senders.yahooinc.com/best-practices/)), and mail without them is sent to spam or refused:

- **SPF**, which allows the service to send for the domain. Your provider gives you the record. A domain has one SPF record: when it already has one, add the provider's `include:` to it, since a second `v=spf1` record makes SPF fail for all of the domain's mail.
- **DKIM**, which signs each email with a key whose public half you publish. Your provider gives you this record as well. The signature should name your sending domain, so its reputation is its own: [Mailgun](./mailgun.md#the-sending-domain) can sign a new subdomain with its parent's key unless you ask otherwise.
- **DMARC**, a TXT record at `_dmarc.news.example.com`, or at `_dmarc.example.com`, which covers the subdomains that have none of their own. If you have none yet, `v=DMARC1; p=none; rua=mailto:dmarc@example.com` is enough to start: it asks for reports and enforces nothing. DMARC passes when SPF or DKIM passes for a domain that matches the From address's.

Adding the domain and its records is your provider's own procedure; each driver's page links to it. Send nothing until the provider shows the domain as verified. Gmail and Yahoo also ask for one-click unsubscribe, which the package adds to every campaign email, and a spam rate kept under 0.1% that never reaches 0.3%, which agents can watch once you set up [Gmail's spam rate](./optional-extras.md#gmails-spam-rate).

Settings that take a domain:

| Setting | Takes |
|---|---|
| `CAMPAIGNS_FROM_ADDRESS` | an address on the sending domain |
| `CAMPAIGNS_DOMAIN` | the sending domain; unset, it's the From address's. A campaign given a From address of its own off this domain is refused |
| the `campaigns` mailer | the sending domain: Mailgun's `domain`, or an SES identity that covers it |
| `campaigns:import-provider-suppressions` | each domain your service has mailed these people from: the sending domain, and your transactional domain if it's on the same account; only the sending domain's unsubscribe list becomes opt-outs, so when the two are one domain, everyone who unsubscribed from any of its mail is kept off your campaigns |
| Google Postmaster Tools | the sending domain itself, since the package asks Google about that exact domain |
| `CAMPAIGNS_URL` | a host on the same domain as the From address: every link and the open pixel point there ([Opens and Clicks](../campaigns/tracking.md#your-domains-reputation)) |
| `campaigns.domain_daily_caps` | the sending domain as its key |

## Set up the driver

Campaigns and the package's own emails (the double opt-in confirmation) go through a driver, the package's code for one sending service, and the driver sends through a mailer named `campaigns` in `config/mail.php`. Pick the service and follow its page, which covers its mailer and how its feedback comes back:

- [Mailgun](./mailgun.md), the default driver (`CAMPAIGNS_DRIVER=mailgun`). Its webhooks bring back bounces, spam reports and unsubscribes.
- [Amazon SES](./ses.md) (`CAMPAIGNS_DRIVER=ses`). Its events come back through Amazon EventBridge.
- [SMTP](./smtp.md) (`CAMPAIGNS_DRIVER=smtp`), any SMTP server. Nothing comes back but what the server refuses while the email is handed over.
- [A driver of your own](../reference/drivers.md) for another service.

Set up the feedback path along with the mailer. Without it, no bounce, spam report or unsubscribe made at the service reaches the package, so the stop-loss can't see a list going bad and every email ends at `sent`. `get_overview` warns while a driver reads nothing back.

On your own machine, set `CAMPAIGNS_DRIVER=fake`, which keeps what it's given and sends nothing, or use the SMTP driver with a mail catcher to read each email as it arrives.

## Set the sender

```dotenv
CAMPAIGNS_FROM_ADDRESS=hello@news.example.com
CAMPAIGNS_FROM_NAME="Example Team"
CAMPAIGNS_REPLY_TO=support@example.com
CAMPAIGNS_POSTAL_ADDRESS="Example Ltd, 1 Main Street, Springfield"
CAMPAIGNS_PRIVACY_URL=https://example.com/privacy
CAMPAIGNS_TEST_ADDRESSES=you@example.com,colleague@example.com
```

- The From address must be on the sending domain. Replies can go anywhere someone reads them.
- The postal address and privacy link appear in every email's footer. Some laws require the address (the US CAN-SPAM Act among them), and mailbox providers and sending services ask for the link. Check what yours ask for.
- Test emails go only to the test addresses. Until you list one, no test email goes out.
- On your own machine, any postal address and privacy link will do. The pre-send `privacy_link` check fails while `CAMPAIGNS_PRIVACY_URL` is unset.

Every link the package puts in an email (unsubscribe, confirmation, the web version, tracked links) starts with `CAMPAIGNS_URL`, which defaults to `APP_URL`, and must be https in production: mail apps' one-click unsubscribe takes only an https address. Mail already delivered keeps pointing there, so that host must keep answering those links for as long as the mail exists. Nothing refuses an http address, so on your own machine an app served over plain http (`php artisan serve`) sends and tracks test emails, but can't use images, which must be https ([below](#store-email-images)).

## Store email images

Images in an uploaded email go into the asset library on `campaigns.assets.disk` (`CAMPAIGNS_ASSETS_DISK`), and emails load them from that disk's public addresses for as long as the mail exists. The disk must give https addresses anyone can read; an image whose address isn't https is refused.

The default is Laravel's `public` disk, which needs two things:

```bash
php artisan storage:link
```

and an https `APP_URL`, since the disk's addresses start with it. Without the link, images upload fine and then answer 404: the pre-send `links` check is the first place that shows it.

On several app servers, a local disk holds each image on the one server that took the upload, so use a shared disk instead, such as an S3 bucket whose files are public. The same goes for uploads: they wait on `campaigns.uploads.disk` (`local` by default) between the upload and the tool call that reads them, which may reach another server, so point `CAMPAIGNS_UPLOADS_DISK` at a shared private disk there.

## Run a queue worker

Sending, webhook processing, the pre-send checks and the double opt-in emails run on the `campaigns` queue (`CAMPAIGNS_QUEUE_CONNECTION` and `CAMPAIGNS_QUEUE` change the connection and the name). Give it a worker of its own, one or two processes:

```bash
php artisan queue:work --queue=campaigns --timeout=330
```

Keep it running under whatever manages your other workers (Supervisor, systemd, your host's queue settings), with the same queue and timeout, and give it longer than the timeout to stop, so a deploy doesn't cut a running job short. Restart workers on every deploy (`php artisan queue:restart`) so they load the new code.

The longest jobs (freezing a large audience, running the checks) may take 300 seconds. Keep the worker's timeout above that, and the time after which the connection hands a job to another worker above the timeout, whichever connection you use:

- **Redis and the database:** `retry_after` in `config/queue.php`, 90 seconds by default. Set it to 360 or more on the connection the campaigns queue uses. If your other queues share that connection, a job whose worker died then waits that long before another worker takes it: give campaigns a connection of its own with the longer `retry_after` and name it in `CAMPAIGNS_QUEUE_CONNECTION`.
- **Amazon SQS:** the queue's visibility timeout, set on the queue in AWS (30 seconds by default), not in Laravel. Set it to 360 or more.
- **Horizon:** a supervisor for the queue, its `timeout` above 300:

  ```php
  // config/horizon.php, under each environment
  'campaigns' => [
      'connection' => 'redis',
      'queue' => ['campaigns'],
      'maxProcesses' => 2,
      'timeout' => 330,
      'tries' => 1,
  ],
  ```

- **`sync`:** fine on your own machine, never in production. Every job then runs inside the request or command that queued it: a large start freezes inside the agent's request, the pump sends inside the scheduler's run, and the minute an open or click waits before it's applied is lost.

## Run the scheduler

The package schedules three commands itself:

| Command | When | What it does |
|---|---|---|
| `campaigns:pump` | every minute | starts scheduled campaigns and hands each sending campaign's next emails to the queue |
| `campaigns:reconcile` | every 15 minutes | settles sends and events that jobs and webhooks left open, from the service's logs when the driver reads them (Mailgun's does) |
| `campaigns:prune` | daily | deletes old provider events and expired uploads |

They run as long as your server runs Laravel's scheduler (`php artisan schedule:run` from cron, or `schedule:work`). To schedule them yourself, set `campaigns.schedule` to `false`.

Each holds a lock while it runs, and the package keeps its locks, a few marks (the pump's last run, when an alert last went) and its latest Gmail and usage readings in `CAMPAIGNS_CACHE_STORE`, the app's default cache store unless you set it. Use a store that:

- your deploys don't flush, so a release can't free a lock a running pump still holds. A deploy's `php artisan cache:clear` empties the default store, and on Redis the whole database that store uses, so give the package a store of its own in `config/cache.php` (the `database` store, or Redis on a connection with a database of its own) and name it in `CAMPAIGNS_CACHE_STORE`;
- lasts between processes: the `array` store forgets everything when each command ends;
- every server that runs the scheduler shares, such as Redis or the database, so two servers don't run the same command at once. Running the scheduler on one server only works too.

## Let services through to your app

Mailgun, Amazon SES's EventBridge, mail apps, image proxies and agents all call your app from servers, not browsers. If a CDN, a firewall or a load balancer stands in front of the app, set it up for these paths:

- `/campaigns/` (the unsubscribe, confirmation, web version and preview pages, the open pixel `/campaigns/o/`, the tracked links `/campaigns/l/`, and the drivers' webhooks);
- the MCP path and everything under it (`/mcp/campaigns`, its upload and download links);
- for OAuth sign-in, if you add it, `/oauth/` and `/.well-known/oauth-`.

On those paths:

- **No bot or browser check.** A challenge page hides every open from a mail app's image proxy, refuses the service's webhooks, and stops agents.
- **No caching.** A cached pixel or redirect records nothing after its first hit. The package answers them with `no-store`, which a CDN's default rules respect; a "cache everything" rule or an edge TTL over `/campaigns/` overrides that.
- **No rewriting of the pages' HTML.** Everything under `/campaigns/` answers with `Cache-Control: no-transform`, which Cloudflare's [email address obfuscation](https://developers.cloudflare.com/waf/tools/scrape-shield/email-address-obfuscation/) respects. A CDN that changes HTML anyway (hiding email addresses behind a script, injecting scripts of its own) breaks the pages: their security policy blocks the injected script, so a reader sees a placeholder where their address should be.

Behind a load balancer or CDN, also make your app trust the proxy's forwarded headers ([Laravel's TrustProxies](https://laravel.com/docs/requests#configuring-trusted-proxies)). The pages are throttled per visitor by IP address, and without it every visitor shares the proxy's.

Whatever stands in front of the app, even nothing, mind the request size. Agents upload files to `/mcp/campaigns/uploads/...`, 25 MB at most by default (`campaigns.uploads.max_size`). Laravel holds every request's body to PHP's `post_max_size`, so raise that to match, and keep both under what your web server (nginx's `client_max_body_size`, for one) and any proxy accept in one request. The upload tool tells agents the smaller of the two limits.

## Keep tokens out of your error tracker

Error trackers keep request paths, bodies and outgoing requests, and some of the package's carry secrets or a person's address: every page link holds a token that unsubscribes or shows one person's email, and a webhook's body holds the recipient's address. If your app reports to one, redact what [Privacy and Security](../reference/privacy-and-security.md#what-your-error-tracker-should-never-see) lists before the first email goes out. Without a tracker, there's nothing to do.

## Fill a list

A campaign goes to people on its lists, so the app needs at least one with people on it:

- **Your users:** a [dynamic list](../lists-and-subscribers/dynamic-lists.md) follows one of your models, kept in step as records change. Bring in the records you already have with `campaigns:sync-list`.
- **Other contacts:** a static list, filled by your own [sign-up form](../lists-and-subscribers/subscriptions.md#a-newsletter-form), an [import](../lists-and-subscribers/imports.md) or an agent.

Mark the lists most campaigns should reach as [default lists](../campaigns/audiences.md#default-lists), through an agent's `update_list` or your code's `UpdateListAction`. A campaign created without lists goes to them, and a list that isn't marked gets only the campaigns that name it. A test email needs no list, so this can wait until your first campaign.

## Connect an agent

Make a token and give it to your agent's MCP client:

```bash
php artisan campaigns:token create laptop
claude mcp add --transport http campaigns https://your-app.example.com/mcp/campaigns \
  --header "Authorization: Bearer <token>"
```

[Connecting Agents](./connecting-agents.md) covers other clients, read-only tokens, and OAuth sign-in for chat apps that can't send a header.

## Turn sending on and send a test

Sending is off until you set `CAMPAIGNS_ENABLED=true`. While it's off, nothing is handed to the queue, campaigns can't start or resume, and no test or double opt-in email goes out. Everything that only records what people chose keeps working: subscriptions, unsubscribes, the consent records. So you can install the package, connect your lists and let agents draft campaigns before anything can reach an inbox.

Once it's on, ask your agent to call `get_overview`, which lists in `sending.notes` what isn't set up yet, then to copy the starter template into a template of your own (`create_template` with `from: "starter"`; the starter itself is read-only) and send a test of it to your test address. When it arrives, check that its images load and that its unsubscribe link opens the package's page on your app.

`get_test_sends` then shows what came back: `sent_at` once your service took the email, or `failure` when it refused it, and `delivered_at` once the service's delivery event reached your app, which proves the feedback path from the [driver step](#set-up-the-driver) works. With the SMTP driver it stays empty, since SMTP reports nothing back. With any other, if it's still empty a few minutes after the email arrived, look at what your service says about its calls to `/campaigns/webhooks/...`: a 406 answer means the signing key or secret doesn't match, and no call at all means the webhook or rule isn't in place.

Then look through [Optional Extras](./optional-extras.md).
