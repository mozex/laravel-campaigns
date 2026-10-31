---
title: Installation
weight: 1
---

## Install the package

```bash
composer require mozex/laravel-campaigns
php artisan campaigns:install
```

The install command publishes `config/campaigns.php` and the migration. If your app keys polymorphic relations by UUID or ULID (`Schema::morphUsingUuids()` or `morphUsingUlids()`), call that in a service provider before you migrate: the link from a subscriber to one of your records follows it. Then run the migration:

```bash
php artisan migrate
```

The tables work on MySQL, MariaDB, PostgreSQL and SQLite. SQL Server isn't supported, since its unique indexes allow only one `NULL`.

## Add the campaigns mailer

Campaigns and the package's own emails (the double opt-in confirmation) go through a driver, the package's code for one sending service, and the driver sends through a mailer named `campaigns`. Send them from a domain of their own, such as `news.example.com`, so a bad week of marketing never touches the reputation of your password resets and receipts.

The default driver is Mailgun's (`CAMPAIGNS_DRIVER=mailgun`). Add its mailer to `config/mail.php`. It must use Mailgun's API (`'scheme' => 'api'`): the SMTP transport would put Mailgun's options into the email as headers.

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

`secret` is a sending key for that domain; use `api.eu.mailgun.net` as the endpoint for a domain in Mailgun's EU region. When the mailer leaves out `client` options, the driver gives it a 10-second idle timeout and 30 seconds in all, so a stuck request ends before the job sending it does. [Mailgun](./mailgun.md) covers the webhooks and the reading key, and [Drivers](../reference/drivers.md) how to add a driver for another service.

To send through any SMTP server instead, see [SMTP](./smtp.md). On your own machine, set `CAMPAIGNS_DRIVER=fake`, which keeps what it's given and sends nothing, or use the SMTP driver with a mail catcher to read each email as it arrives.

## Set the sender

```dotenv
CAMPAIGNS_FROM_ADDRESS=hello@news.example.com
CAMPAIGNS_FROM_NAME="Example Team"
CAMPAIGNS_REPLY_TO=support@example.com
CAMPAIGNS_POSTAL_ADDRESS="Example Ltd, 1 Main Street, Springfield"
CAMPAIGNS_PRIVACY_URL=https://example.com/privacy
CAMPAIGNS_ALERT_ADDRESS=ops@example.com
CAMPAIGNS_TEST_ADDRESSES=you@example.com,colleague@example.com
```

- The From address must be on the marketing domain: `CAMPAIGNS_DOMAIN` when you set it, else the sender's own domain. Replies can go anywhere someone reads them.
- The postal address and privacy link appear in every email's footer. Some laws (the US CAN-SPAM Act among them) require the address, and mailbox providers and sending services ask for the link.
- The alert address hears about automatic pauses, a campaign's first spam report and a stalled queue, through your app's default mailer.
- Test emails go only to the test addresses. Until you list one, no test email goes out.

Every link the package puts in an email (unsubscribe, confirmation, the web version) starts with `CAMPAIGNS_URL`, which defaults to `APP_URL`. Mail already delivered keeps pointing there, so that host must keep answering those links for as long as the mail exists.

## Run a queue worker

Sending, webhook processing, the pre-send checks and the double opt-in emails run on the `campaigns` queue (`CAMPAIGNS_QUEUE_CONNECTION` and `CAMPAIGNS_QUEUE` change it). Give it a worker of its own, one or two processes:

```bash
php artisan queue:work --queue=campaigns --timeout=330
```

The longest jobs (freezing a large audience, running the checks) may take 300 seconds. Keep the worker's `--timeout` above that, and your queue connection's `retry_after` above the worker's timeout: Laravel's Redis connection defaults to 90 seconds, and a job still running past `retry_after` is handed to a second worker.

## Run the scheduler

The package schedules three commands itself:

| Command | When | What it does |
|---|---|---|
| `campaigns:pump` | every minute | starts scheduled campaigns and hands each sending campaign's next emails to the queue |
| `campaigns:reconcile` | every 15 minutes | settles sends and events that jobs and webhooks left open, from the service's logs when the driver reads them (Mailgun's does) |
| `campaigns:prune` | daily | deletes old provider events and expired uploads |

They run as long as your server runs Laravel's scheduler (`php artisan schedule:run` from cron, or `schedule:work`). Their overlap locks live in `CAMPAIGNS_CACHE_STORE`, the app's default cache store unless you set it. Point it at a store your deploys don't flush, so a release can't free a lock a running pump still holds. To schedule them yourself, set `campaigns.schedule` to `false`.

## Turn sending on

Sending is off until you set `CAMPAIGNS_ENABLED=true`. While it's off, nothing is handed to the queue, campaigns can't start or resume, and no test or double opt-in email goes out. Everything that only records what people chose keeps working: subscriptions, unsubscribes, the consent records. So you can install the package, connect your lists and let agents draft campaigns before anything can reach an inbox.
