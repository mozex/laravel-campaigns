---
title: Testing Your App
weight: 4
---

## The fake driver

Set the driver to `fake` in your test environment, in `phpunit.xml`:

```xml
<env name="CAMPAIGNS_DRIVER" value="fake"/>
```

It keeps every message it's given and sends nothing, the double opt-in confirmation included. In production it refuses every send, so a campaign left on it pauses instead of being marked sent. Get it from the driver manager, which keeps the one it built, to read and script it:

```php
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\SendFailed;

/** @var FakeDriver $driver */
$driver = app(DriverManager::class)->get('fake');

$driver->sent();                          // every OutgoingMessage sent: to, subject, html, text...
$driver->failNext(new SendFailed(SendFailure::NotAccepted, 'Rate limited', 429));
$driver->count('2026-01-15', ['sent' => 1200, 'hard_bounces' => 3]);  // the domain's counts that day
$driver->failReads();                     // every read of logs, suppression lists or metrics throws
$driver->readsWork();                     // until this
$driver->keepLogsFor(30);
```

`log()` and `suppress()` fill its logs and suppression lists, for code that reconciles or imports them, and `webhook()` gives the next request to `/campaigns/webhooks/fake` the events it carries (a request made without it is refused).

The fake has every ability a driver can have. To see what your code does with a driver that has fewer, put `FakeSendOnlyDriver` (it only sends) or `FakeWebhookDriver` (webhooks and suppression lists, no logs or counts) in its place; both take the same calls:

```php
use Mozex\Campaigns\Sending\Drivers\FakeSendOnlyDriver;

app(DriverManager::class)->swap('fake', new FakeSendOnlyDriver);
```

## Queues and time

Sending runs through queued jobs. Fake only the send job, so a campaign you start still freezes its audience:

```php
Queue::fake([\Mozex\Campaigns\Jobs\SendMessageJob::class]);
```

The pace, the sending hours and the stop-loss read the clock through `now()`, so `$this->travelTo()` moves them. Travel to UTC instants: a time given in another zone can land on a different day than you meant.

## Hosts

The pre-send checks and image fetches resolve host names before connecting. Bind the static resolver so a test never makes a real DNS lookup:

```php
use Mozex\Campaigns\Fetching\HostResolver;
use Mozex\Campaigns\Fetching\StaticHostResolver;

$this->app->instance(HostResolver::class, new StaticHostResolver(['example.com' => ['93.184.215.14']]));
```

## Factories

Every model has a factory in `Mozex\Campaigns\Database\Factories`, autoloaded with the package, so your tests can make subscribers, lists and campaigns directly: `Subscriber::factory()->create()`.

## Consent in tests

Records your tests create under a list source sync onto their dynamic list as they would in production, as made by staff unless you wrap them in `withCampaignConsent()`. Listeners on the package's events are run after the commit and their failures reported, not thrown, so assert after the action rather than inside a listener.
