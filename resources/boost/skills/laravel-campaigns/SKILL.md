---
name: laravel-campaigns
description: "Set up mozex/laravel-campaigns in a Laravel app and extend it - the setup in order (sending domain and its DNS, the campaigns mailer and feedback path for Mailgun, Amazon SES, SMTP or a custom driver, images, queue, scheduler, CDN and proxies) and the optional extras, dynamic lists from app models (ListSource, HasCampaignSubscriber, campaigns:sync-list), recording consent on sign-up forms (withCampaignConsent, ConsentContext), newsletter and double opt-in sign-ups, imports from another email service (ImportContactsAction), named audiences and agent resources, MCP tokens, OAuth guards and who may connect (HasCampaignRights), events, error-tracker redaction, and testing with the fake driver. Use whenever the user works on email marketing, newsletters, unsubscribes, marketing consent, mailing lists or campaigns in an app that has this package, even when they don't name it."
---

# Laravel Campaigns

This app has `mozex/laravel-campaigns` installed. AI agents run its email marketing through an MCP server the package adds (`/mcp/campaigns` by default); the app's own code feeds it lists, consent, audiences and data. There are no screens. Every change to a subscriber, list, template or campaign is a package action in `Mozex\Campaigns\Actions`, and the MCP tools only call those actions.

## When to use this skill

- A model should become a mailing list (user accounts, customers), or its sign-up form asks about marketing email.
- A newsletter form, a double opt-in list, or an unsubscribe needs handling in app code.
- Contacts, opt-outs or bounces come over from another email service.
- Agents need app data: an audience by the app's own records ("people who bought X") or read-only data (products, a brand guide).
- Setting up the mailer, Mailgun's webhooks, Amazon SES's events, a driver for another sending service, the queue worker, the scheduler, MCP tokens or OAuth sign-in.
- Writing tests for any of it.

## Rules that keep consent provable

- **Change subscribers only through the actions.** Each writes the consent ledger record a complaint is answered with. Never update `opted_out_at`, `blocked_at` or subscriptions with Eloquent directly.
- **Only the person clears an opt-out**: a `ConsentContext` with `accepted: true` (they ticked a box or asked again) or a confirmed double opt-in. Syncs, imports and agents never do.
- **One unsubscribe stops all marketing.** `opted_out_at` is app-wide; list subscriptions stay for history. Write sign-up copy that says so.
- **Sending is off until `CAMPAIGNS_ENABLED=true`.** While off, campaigns can't start and no double opt-in or test email goes out; recording consent still works.

## A dynamic list from a model

```php
use Mozex\Campaigns\Contracts\ListSource;

class AccountList implements ListSource
{
    public function name(): string { return 'Accounts'; }

    // Every record, eligibility left out; eager-load what fields() and isEligible() read.
    public function query(): Builder { return User::query(); }

    // The app's own rule; leaving and coming back is not a consent change.
    public function isEligible(Model $record): bool { return $record->banned_at === null; }

    // email is required; only the keys returned are kept in step.
    public function fields(Model $record): array
    {
        return ['email' => $record->email, 'name' => $record->name, 'locale' => $record->locale];
    }
}
```

Add `use Mozex\Campaigns\Concerns\HasCampaignSubscriber;` to the model and register the source in `config/campaigns.php` under `list_sources` (`'accounts' => AccountList::class`). Existing records come in with `php artisan campaigns:sync-list accounts` (a dry run), then `--id=<key> --force` for one, then `--force`. After that, creates, updates, address changes and deletes sync themselves; a sync failure is reported, never thrown into the app's save. A delete that fires no model event (`User::where(...)->delete()`, a database cascade, a mass prune) is invisible to the trait: run `campaigns:sync-list <key>` afterwards, read its "Forgotten" count, then run it with `--force`, which forgets subscribers whose record is gone. Forgetting can't be undone, so never schedule the forced run. Nobody is added to or removed from a dynamic list by hand. List-source keys use lower-case letters, digits, dots, dashes and underscores.

## Recording a sign-up's choice

```php
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Support\ConsentContext;

$user = User::withCampaignConsent(
    ConsentContext::fromRequest($request, 'registration', ConsentBasis::Consent, accepted: $request->boolean('newsletter')),
    fn (): User => User::create($attributes),
);
```

`accepted: true` records consent with the IP and browser; `false` records the refusal and opts the person out; leave `accepted` out for a note with no box (the basis, notice version and request are kept, an earlier opt-out stays). A record that isn't eligible when created (an unconfirmed account) gets no subscriber unless the context holds a choice: wrap the save that makes it eligible (`markEmailAsVerified()`) in `withCampaignConsent()` too, and it joins under that context; only a record that becomes eligible in that save takes it, never its `accepted` (record a box ticked then with `SubscribeAction` after the save); Fortify's or Breeze's verification route gets a middleware that runs the request inside `withCampaignConsent()`. Without one the record joins on the list's default basis (`soft_opt_in`) from source `staff` (created) or `sync`. The `source` is a short label of where the change came from (at most 125 characters); agents see each list's joins of the last 30 days counted by it in `list_lists`, so keep it to a few stable names (`registration`, `newsletter-form`), never one per page or per person.

## Static lists, newsletters and double opt-in

Agents create static lists through the MCP tools; app code can call `CreateListAction` (`key`, `name`, `description`, `doubleOptIn`, `basis`, `isDefault`). The app's own code keeps its lists filled: its records through a list source, its forms through `SubscribeAction`. A default list (`is_default`) receives every campaign created without lists of its own, and a list not marked default only the campaigns that name it; naming lists replaces the defaults. The defaults are read when the campaign is created, so marking a list later changes no existing campaign; archiving a list takes the mark away, and an archived list can't take it. Mark one from code with `app(UpdateListAction::class)->execute(Campaigns::list('accounts'), ['is_default' => true])` (`Campaigns::list()` makes a dynamic list that doesn't exist yet; `CampaignList::forKey()` finds a static one); a dynamic list takes only that change, its name and description being the source's. A newsletter form:

```php
// email:filter_unicode checks the address as the package does; a plain email rule passes some it refuses.
$request->validate(['email' => ['required', 'string', 'max:254', 'email:filter_unicode']]);

$subscription = app(SubscribeAction::class)->execute(
    Subscriber::forEmail($request->input('email')),   // cleans the address, makes the row once even when two requests race
    CampaignList::forKey('newsletter'),
    ConsentContext::fromRequest($request, 'newsletter-form', ConsentBasis::Consent, accepted: true),
);

app(SendConfirmationAction::class)->execute($subscription);   // mails the link on a double opt-in list; does nothing otherwise
```

Never build a subscriber with `Subscriber::firstOrCreate()`: it compares the raw address. `SubscribeAction` refuses a context with `accepted: false`; record an unticked box with `DeclineAction`. On a double opt-in list the person waits as `pending`: one confirmation email per address a day, nothing while sending is off (call `SendConfirmationAction` again for sign-ups made then), and an address change voids the link already sent. Agents' own additions to a double opt-in list are never mailed a confirmation.

Other actions: `OptOutAction`, `OptInAction`, `BlockAction` (with a `BlockReason`), `UnblockAction`, `RemoveFromListAction` (static lists), `ChangeEmailAction`, `ForgetSubscriberAction` (an erasure request; a blocked or opted-out address keeps its row stripped to the address, with its consent records, and its sent mail is unlinked). `Subscriber::findByEmail()` and `Subscriber::findFor($record)` look people up.

## Imports from another service

```php
$result = app(ImportContactsAction::class)->execute(
    contacts: $rows,                 // iterable of ImportedContact or arrays keyed by ImportedContact::FIELDS; streamed
    list: CampaignList::forKey('newsletter'),   // a static list, or null for opt-outs, blocks and engagement only
    context: new ConsentContext('old-service-import', ConsentBasis::Imported),
    dryRun: ! $this->option('force'),
    only: $this->option('email'),    // one address first
);
```

An import never clears an opt-out or block, never changes an existing subscription, gives record-linked subscribers no details, and only moves engagement dates forward. `$result->toArray()` names problems by row number, never by address. Agents import up to 2,000 rows from a CSV themselves; larger lists need a command like this one.

## Named audiences and resources

`Mozex\Campaigns\Contracts\Audience` (`campaigns.audiences`): `description()`, `parameters(JsonSchema $schema)` declared like an MCP tool's input, `rules()` validated first, and `query(array $parameters)` returning a query on the app's model (whose linked subscribers form the audience, so the model must feed a dynamic list) or on `Subscriber`. It is used as a key-only subquery: no limits, filter with `where()`, `has()`, `whereHas()`. It only narrows a campaign's target lists; opted-out, blocked and sunset people are never mailed.

`Mozex\Campaigns\Contracts\AgentResource` (`campaigns.resources`): `description()` and `read(int $page): Paginator`, mapping each item to the fields an agent needs; one page must fit in 50,000 characters of JSON. Keys become addresses: letters, digits and dashes.

## Setup, in order

Every step here is required where it applies to the app; the docs' installation page has each in full. Steps at the sending service and the DNS host (the domain, its records, webhooks, keys) happen in their dashboards: unless you have tools for them, give the person one step at a time with the exact values to enter, and link the provider's own docs rather than guessing its screens.

1. `composer require mozex/laravel-campaigns`, `php artisan campaigns:install`, `php artisan migrate` (call `Schema::morphUsingUuids()` or `morphUsingUlids()` first if the app keys morphs that way). With Laravel Boost, the package's guideline and skill reach the app once `mozex/laravel-campaigns` is in `boost.json`'s `packages`, then `php artisan boost:update`; its interactive run offers new packages, a non-interactive one never adds them.
2. A sending domain kept for marketing (`news.example.com`), with SPF, DKIM and a DMARC record (`p=none` is enough to start); Gmail and Yahoo refuse or spam bulk mail without all three. The From address is on it; `CAMPAIGNS_DOMAIN` pins it. A domain has one SPF record: add the provider's `include:` to an existing one. The app's own domain works too, sharing its reputation, its suppressions (a campaign's bounce or spam report stops that person's receipts too) and its Gmail reading with transactional mail.
3. The driver (`CAMPAIGNS_DRIVER`), a `campaigns` mailer in `config/mail.php`, and its feedback path, which is required: without it no bounce, spam report or service unsubscribe reaches the package.
   - `mailgun` (default): `'transport' => 'mailgun', 'scheme' => 'api'` with the domain, its sending key and `endpoint` (`api.mailgun.net` for a US domain, `api.eu.mailgun.net` for an EU one); the domain's webhooks (`delivered`, `permanent_fail`, `temporary_fail`, `complained`, `unsubscribed`) to `/campaigns/webhooks/mailgun`, added a few minutes before the first email beside any the app already has, and `MAILGUN_WEBHOOK_SIGNING_KEY` from Mailgun's API Security page. The app's own mailgun mailer can serve through `CAMPAIGNS_MAILER` only with `'scheme' => 'api'` (Laravel's default is Mailgun's HTTP transport, which the driver refuses). A subdomain whose root is on the same account may sign with the root's DKIM key: its DKIM record's name should end in `._domainkey.<the subdomain>`. A new DKIM key activates only once its record resolves.
   - `ses`: `composer require aws/aws-sdk-php` (3.393.3 or later, which can turn SES's own tracking off per email), the mailer on Laravel's `ses-v2` transport with `'retries' => 0` (any other count is refused, since the SDK's retries can send an email twice), its own `key` and `secret` (left empty, the SDK's default credentials apply), the identity's region, the sending domain verified as an identity of its own with Easy DKIM (a parent identity signs with the parent's keys, and VDM may count nothing for the subdomain), and a configuration set for campaigns alone in `options.ConfigurationSetName`, whose event destination is Amazon EventBridge without opens and clicks; an EventBridge rule on `source: aws.ses`, the six sending `detail-type`s and the set's `ses:configuration-set` tag, targeting an API destination at `/campaigns/webhooks/ses` (with an execution role allowing `events:InvokeApiDestination`), its connection's API key header `X-Campaigns-Webhook-Key` holding `CAMPAIGNS_SES_WEBHOOK_SECRET`; the identity's email feedback forwarding off (a forwarded spam report holding the email can record an open for the person who complained); production access in that region. The mailer's credentials need `ses:SendEmail`, `ses:SendRawEmail` and `ses:ApplyTrackingConfigurationOverrides` to send (SES answers a denied send with 403, and every campaign on the domain pauses with a reason naming the three), `ses:ListSuppressedDestinations` and `ses:GetSuppressedDestination` to read the suppression list, `ses:GetAccount` to read the sending quota, and `ses:BatchGetMetricData` with VDM. SES keeps no logs, so an event that never arrived is lost; its suppression list is the account's, and holds back the account's other mail to a campaign's bounces and complainers unless a configuration set keeps its own.
   - `smtp`: Laravel's `smtp` transport to a relay allowed to send for the domain (or a mail catcher such as Mailpit locally), its host, port, username and password from the app's own env names. It reads back only what the server refuses on the spot, so `get_overview` warns about it. Locally, `CAMPAIGNS_DRIVER=fake` sends nothing.
4. The sender: `CAMPAIGNS_FROM_ADDRESS`, `CAMPAIGNS_FROM_NAME`, `CAMPAIGNS_REPLY_TO`, `CAMPAIGNS_POSTAL_ADDRESS`, `CAMPAIGNS_PRIVACY_URL`, `CAMPAIGNS_TEST_ADDRESSES`. `CAMPAIGNS_URL` (default `APP_URL`) is https in production and stays answering, since delivered mail links there; locally an http app sends and tracks test emails, but images need https.
5. Images: `campaigns.assets.disk` must give public https addresses. The default `public` disk needs `php artisan storage:link` and an https `APP_URL` (a missing link shows only as the `links` check's 404). On several servers, a shared disk for assets and for `campaigns.uploads.disk`.
6. A worker for the `campaigns` queue with `--timeout` above 300 seconds, kept running by the app's process manager with a longer stop wait and restarted on deploy (`queue:restart`), and the connection's hand-over time above that: `retry_after` for Redis and the database (a connection of its own, `CAMPAIGNS_QUEUE_CONNECTION`, when other queues share it), the queue's visibility timeout for SQS, a Horizon supervisor's `timeout`. Never `sync` in production.
7. The scheduler (the package schedules its pump, reconciler and pruning), with `CAMPAIGNS_CACHE_STORE` on a store deploys don't flush (`cache:clear` empties the default store, on Redis its whole database), never `array`, shared by every server that runs the scheduler.
8. Whatever is in front of the app: PHP's `post_max_size` and the web server's request limit at least `campaigns.uploads.max_size` (25 MB). With a CDN or firewall: no bot or browser check, no caching and no HTML rewriting (such as email address obfuscation) on `/campaigns/` and the MCP path with its sub-paths (`/oauth/` and `/.well-known/oauth-` too with OAuth); the app trusting the proxy's forwarded headers, since the pages throttle per visitor IP.
9. The error tracker's redaction (below).
10. A list with people on it (a list source, a form, an import), and the usual lists marked default (`update_list`, `UpdateListAction`) before the first campaign; a test needs no list.
11. `php artisan campaigns:token create <name> [--read-only]` for an agent's machine; any MCP client takes the URL (`/mcp/campaigns`) and an `Authorization: Bearer` header.
12. `CAMPAIGNS_ENABLED=true`, then a test email to a test address from a template of the app's own (`create_template` with `from: "starter"` copies the read-only starter). `get_test_sends` shows `delivered_at` once the provider's delivery event reached the app, which proves the feedback path (never on SMTP); empty minutes after the email arrived, the service's record of its calls to `/campaigns/webhooks/...` says why (406: the key doesn't match).

## Optional extras

Each is off until set up, and a missing one fails nothing: `get_overview`'s `sending.notes` names what it would add.

- `CAMPAIGNS_MAILGUN_API_KEY`, an account key (Support role where the plan has roles): Mailgun's logs (missed events, unclear sends), suppression lists (`campaigns:import-provider-suppressions <domain>` before the first campaign, for every domain that mailed the same people), daily counts and usage. `CAMPAIGNS_MAILGUN_LOG_DAYS` matches the plan's log retention (5 by default).
- `CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED`: the plan's monthly emails, so a start, resume or speed-up warns when a campaign would pass it (the count is the whole account's, both regions summed).
- `CAMPAIGNS_SES_VDM=true` with SES's Virtual Deliverability Manager on: SES's daily counts.
- `CAMPAIGNS_POSTMASTER_CREDENTIALS`: a Google service account's JSON key, base64-encoded, whose address is a user of the exact sending domain in Google Postmaster Tools (the Gmail Postmaster Tools API on in its project), so `get_overview` and the start, resume and speed-up answers show Gmail's spam rate. The error tracker censors `Authorization` on outgoing requests, the token request's `assertion` and its response's `access_token`.
- `CAMPAIGNS_ALERT_ADDRESS`: mailed through the app's default mailer on an automatic pause, a first spam report and a stalled queue.
- `campaigns.checks.spam_score`: a SpamAssassin score from Postmark's free checker among the pre-send checks.
- OAuth for chat apps (claude.ai, Claude's desktop app, ChatGPT): Passport with laravel/mcp's `Mcp::oauthRoutes()`, then `'guards' => ['campaigns', 'api']` under `campaigns.mcp`, and the user model implementing `Mozex\Campaigns\Contracts\HasCampaignRights`: `campaignRights(): ?Rights` answers `Rights::Full`, `Rights::Read` or `null` (refused with a 403, as is a model without the contract), read once a request. The package asks Laravel's Gate nothing, so a `Gate::before()` or a permission package's super-admin role grants nothing unless the method calls `can()`. The method holds the app's own account checks (a ban), since the server's route runs none of the app's `web` or `api` middleware, only `campaigns.mcp.middleware`. An OAuth sign-in makes `api` the default guard, so with spatie/laravel-permission name the guard: `$this->can('manage campaigns', 'web')`. Under a token the request's user is an `ApiToken`: code an MCP call reaches (audiences, resources, listeners on the package's events) never asks the Gate about the request's user, since callbacks written for the app's user model throw on it, Spatie's and Filament Shield's included; use `Gate::forUser($user)` for a user it holds.
- `campaigns.mcp.instructions` for the app's own rules for agents: about 2,100 characters fit beside the package's.

## Another sending service

A driver implements `Mozex\Campaigns\Contracts\CampaignDriver` (`send(OutgoingMessage): SendReceipt`, throwing `SendFailed` with `NotAccepted`, `DomainRefused`, `Rejected` (this email refused for good; a `DeliveryFailure::HardBounce` as its fourth argument for a mailbox that doesn't exist) or `Unclear`, decided by status and error code, never the service's words, and no address in the message) plus the abilities its service has in `Contracts\Drivers`: `ReceivesWebhooks`, `ReadsEvents`, `ReadsSuppressions`, `ReadsMetrics`, `ReadsUsage` (`usage(): Usage`, the whole account's count in its calendar month or last 24 hours against its allowance). It turns its service's own open and click tracking off on every email and drops the service's open and click events: the package wraps the links and adds the pixel itself (`OutgoingMessage::$trackOpens` and `$trackClicks` say whether it did). Register it in a service provider's `boot()` with `Campaigns::extend('name', fn (array $config, Application $app) => new NameDriver($config))`; it gets `campaigns.drivers.name` and answers webhooks at `/campaigns/webhooks/name`. Names are lower-case, at most 50 characters; `campaigns` is reserved. An ability that needs a setting (a key that reads the service's logs) says so through `Contracts\Drivers\ChecksSetup::missingSetup()`, and the package then treats it as missing, quietly. `get_overview` says what a missing ability costs.

## Error trackers

Redact: paths under `/campaigns/u/`, `/campaigns/c/`, `/campaigns/v/`, `/campaigns/o/` and `/campaigns/l/` (link and tracking tokens); the `signature` query of `/campaigns/preview/` and of the upload and download links under the MCP path; the body of the upload `PUT`; the body of `POST /campaigns/webhooks/<driver>` (Mailgun's `event-data` field, Amazon SES's `detail`) and its `X-Campaigns-Webhook-Key` header; MCP tool call arguments; outgoing requests to `api.*.mailgun.net/v3/<domain>/bounces`, `/complaints` and `/unsubscribes` and to `email.<region>.amazonaws.com/v2/email/suppression/addresses/` (path and query); the `Authorization` header.

## Events

`CampaignStartedEvent`, `CampaignPausedEvent` (`reason`, `automatic`), `CampaignCompletedEvent`, `CampaignQueueStalledEvent`, `CampaignFirstComplaintEvent`, `SubscriberUnsubscribedEvent`, `SubscriberBlockedEvent`, in `Mozex\Campaigns\Events`. They fire after commit; a listener's exception is reported, not thrown.

## Testing

- `CAMPAIGNS_DRIVER=fake` in `phpunit.xml`; `app(DriverManager::class)->get('fake')` is the `FakeDriver` (`Mozex\Campaigns\Sending\Drivers`): `sent()`, `failNext()`, `count($date, $counts)`, `used($sent, $allowance)` (the usage is kept in `campaigns.cache_store` for an hour, so clear it before changing it in a test), `log()`, `suppress()`, `webhook()`, `failReads()` and `readsWork()`. `->swap('fake', new FakeSendOnlyDriver)` tests a driver with no abilities.
- `Queue::fake([SendMessageJob::class])` only, so a started campaign still freezes. Travel time with UTC instants.
- Bind `StaticHostResolver` in place of `HostResolver` so link checks and image fetches never look up real hosts.
- Factories for every model ship with the package: `Subscriber::factory()`, `CampaignList::factory()`, `Campaign::factory()`.
- Assert what an event listener did after the action, never inside it: its failures are reported, not thrown.
