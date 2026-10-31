---
title: Drivers
weight: 5
---

A driver is the package's code for one sending service. `campaigns.driver` (`CAMPAIGNS_DRIVER`) names the one that sends: `mailgun` (the default), `smtp` (any SMTP server, see [SMTP](../getting-started/smtp.md)), `fake` (sends nothing, for tests and local runs), or one you register. Every email the package sends goes through it: campaign emails, test emails and the double opt-in confirmation. Only the alert mail goes through the app's default mailer.

## The contract

A driver implements `Mozex\Campaigns\Contracts\CampaignDriver`, which asks for one method:

```php
public function send(OutgoingMessage $message): SendReceipt;
```

What else a service offers, a driver says by implementing the abilities in `Mozex\Campaigns\Contracts\Drivers`. The package checks for each one before using it, and says what it can't do when a driver lacks one (in `get_overview`'s `sending.notes`, or in the command's or tool's answer).

| Ability | Methods | Without it |
|---|---|---|
| `ReceivesWebhooks` | `verifyWebhook()`, `parseWebhook()` | `/campaigns/webhooks/<name>` answers 404; the package learns what happened to an email only from the logs, if the driver reads them |
| `ReadsEvents` | `eventsForMessage()`, `eventsBetween()`, `logDays()` | nothing a webhook missed is replayed, an email whose send ended unclear stays `unknown`, never resent, and one its job left `sending` becomes `unknown` after 15 minutes |
| `ReadsSuppressions` | `suppressions()`, `suppressionsFor()` | `campaigns:import-provider-suppressions` refuses, and `unblock_contact` answers `checked: false` with the reason |
| `ReadsMetrics` | `domainMetrics()` | `get_overview`'s `domain.provider` is null, with a note |

A driver with neither `ReceivesWebhooks` nor `ReadsEvents` reads nothing back: its emails end at `sent`, never `delivered`, the report divides opens and clicks by sent emails, and the stop-loss sees only what's refused while sending ([Feedback](../campaigns/feedback.md#drivers-that-read-nothing-back)). `get_overview`, `start_campaign` and `resume_campaign` warn about it.

The Mailgun driver has all four abilities, the SMTP driver none. The fake has all four too; `FakeSendOnlyDriver` (send only) and `FakeWebhookDriver` (webhooks and suppression lists) share its `FakesDriver` trait, for testing what the package does with fewer.

## Registering a driver

Register yours from a service provider's `boot()`:

```php
use Illuminate\Contracts\Foundation\Application;
use Mozex\Campaigns\Facades\Campaigns;

public function boot(): void
{
    Campaigns::extend('postmark', fn (array $config, Application $app) => new PostmarkDriver($config));
}
```

The creator gets the driver's own block, `campaigns.drivers.postmark` in `config/campaigns.php`, and the app. Use that `$app` argument rather than `$this`: Laravel binds the closure to the driver manager, so `$this` inside it isn't your service provider. Then set `CAMPAIGNS_DRIVER=postmark`. A name is lower-case letters, digits, `-` and `_`, starting with a letter and at most 50 characters, since it's a path segment of the driver's webhook address. `campaigns` is reserved: the package records its own opens and clicks under it. A name a built-in driver has replaces that driver.

A driver that sends through a Laravel mailer uses `campaigns.mail.mailer` and refuses a transport it can't drive. A send must end before the send job's 60-second timeout. When the app gave the mailer no time limits, the package sets some for the built-in drivers while it registers: 10 seconds idle and 30 in all for Mailgun's, 10 seconds for each read of an SMTP server's reply. A driver of your own needs limits of its own.

## Sending

`send()` either returns or throws `Mozex\Campaigns\Sending\SendFailed` with a `SendFailure` that tells the package what happened, so an email is never sent twice:

- `NotAccepted`: the service took nothing and may take it later (a rate limit, an outage). The email goes back to waiting and the campaign's rate halves for ten minutes.
- `DomainRefused`: nothing more can go from this domain until someone acts (a revoked key, a disabled domain). Every campaign on the domain pauses.
- `Rejected`: the service refuses this one email for good (an SMTP server's 5xx reply to it). The email fails and is never resent. Pass a `DeliveryFailure::HardBounce` as `SendFailed`'s fourth argument when the refusal names a mailbox that doesn't exist: the email then counts as sent and bounced, and the address is blocked. Any other refusal fails as `rejected`, and 10 of them in a row pause the campaign.
- `Unclear`: anything you can't be sure of, such as a timeout after the request went out. The email is marked unknown, never resent, and the reconciler looks it up in the logs when the driver reads them.

Decide by transport facts (no connection, no answer), the service's status and its error code, never by the words in its answer, which change without notice. Keep addresses out of the exception's message and never chain the transport's exception: both reach logs and error trackers.

The receipt holds the service's own id for the email, which the package stores and matches events against when an event carries no uuid. Send `OutgoingMessage::metadata()` with the email, so the service can echo the uuid back in its events.

## Webhooks

A service posts to `/campaigns/webhooks/<name>`. The route answers for a driver that is active, or registered with `Campaigns::extend()`, or whose block holds its webhook credential (Mailgun's `webhook_signing_key`), so the old driver's late events still land after a switch; the fake answers only while it is active. A driver that isn't configured gets a 404 without being built, and one that doesn't receive webhooks a 404 too.

`verifyWebhook()` returns false for a request that isn't the service's (406), and throws a `CampaignsException` when the driver isn't set up to verify one (500, reported at most once an hour, so the service retries while you fix the config). Refuse a request dated more than 24 hours from now in either direction: the package keeps the one-time values it has seen for 48 hours.

`parseWebhook()` returns a list of `Mozex\Campaigns\Sending\Feedback`. The package stores each event once by its `providerEventId` and the driver's name, so give an event the same id whether it came by webhook or from the logs. Set `replayKey` only when the request carries a signed one-time value (Mailgun's token); a request verified by a shared secret needs none. Put the service's details worth keeping in `payload`, never a recipient's address or IP, and the package's terms in `failure` (`DeliveryFailure`).

## Reading

Every read may throw `Mozex\Campaigns\CampaignsException` when the service can't be read; the reconciler reports it at most once an hour and carries on. The reconciler reads the logs through the driver that sent each email, each with its own `logDays()`, and skips a driver without logs or one no longer configured.

`eventsForMessage()` returns `null` when it can't tell (more pages than it read, or no way to search by uuid), never an empty list, which marks the email failed an hour after it went.

`suppressions()` and `suppressionsFor()` take the driver's sending scope as `$domain`. A service with one list for the whole account ignores it, and its driver's docs say so.

`domainMetrics()` returns a `Mozex\Campaigns\Sending\DomainMetrics` whose `days` are keyed by UTC date (`Y-m-d`) within the period, each with `sent`, `forwarded`, `hard_bounces`, `complaints` and `unsubscribes`. A count the service doesn't keep is `null`, never 0, which would read as "none happened".

## Contributing a driver

A pull request for a new driver brings the driver in `src/Sending/Drivers/`, its block in `config/campaigns.php`, its creator in `DriverManager`, its service's documented payloads as fixtures under `tests/Fixtures/`, a test of each rule above, and a getting-started page beside [Mailgun](../getting-started/mailgun.md). Its service's SDK goes in `suggest` and `require-dev`, never `require`.
