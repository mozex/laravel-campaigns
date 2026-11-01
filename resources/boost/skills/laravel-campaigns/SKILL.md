---
name: laravel-campaigns
description: Wire mozex/laravel-campaigns into a Laravel app and extend it - dynamic lists from app models (ListSource, HasCampaignSubscriber, campaigns:sync-list), recording consent on sign-up forms (withCampaignConsent, ConsentContext), newsletter and double opt-in sign-ups, imports from another email service (ImportContactsAction), named audiences and agent resources, the campaigns mailer, Mailgun webhooks and sending drivers for other services (Campaigns::extend), MCP tokens, OAuth guards and gate abilities, events, error-tracker redaction, and testing with the fake driver. Use whenever the user works on email marketing, newsletters, unsubscribes, marketing consent, mailing lists or campaigns in an app that has this package, even when they don't name it.
---

# Laravel Campaigns

This app has `mozex/laravel-campaigns` installed. AI agents run its email marketing through an MCP server the package adds (`/mcp/campaigns` by default); the app's own code feeds it lists, consent, audiences and data. There are no screens. Every change to a subscriber, list, template or campaign is a package action in `Mozex\Campaigns\Actions`, and the MCP tools only call those actions.

## When to use this skill

- A model should become a mailing list (user accounts, customers), or its sign-up form asks about marketing email.
- A newsletter form, a double opt-in list, or an unsubscribe needs handling in app code.
- Contacts, opt-outs or bounces come over from another email service.
- Agents need app data: an audience by the app's own records ("people who bought X") or read-only data (products, a brand guide).
- Setting up the mailer, Mailgun's webhooks, a driver for another sending service, the queue worker, the scheduler, MCP tokens or OAuth sign-in.
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

`accepted: true` records consent with the IP and browser; `false` records the refusal and opts the person out; leave the context out when the person made no choice (the list's default basis, `soft_opt_in`, applies). The `source` is a short label of where the change came from (at most 125 characters).

## Static lists, newsletters and double opt-in

Agents create static lists through the MCP tools; app code can call `CreateListAction` (`key`, `name`, `description`, `doubleOptIn`, `basis`). A newsletter form:

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

Other actions: `OptOutAction`, `OptInAction`, `BlockAction` (with a `BlockReason`), `UnblockAction`, `RemoveFromListAction` (static lists), `ChangeEmailAction`, `ForgetSubscriberAction` (an erasure request; a blocked or opted-out address keeps its row stripped to the address). `Subscriber::findByEmail()` and `Subscriber::findFor($record)` look people up.

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

## Setup checklist

- A `campaigns` mailer in `config/mail.php`: `'transport' => 'mailgun', 'scheme' => 'api'` with the marketing domain's `domain`, `secret` and `endpoint`, for the default `mailgun` driver. For `CAMPAIGNS_DRIVER=smtp`, the mailer is Laravel's `smtp` transport (a relay, or a mail catcher such as Mailpit locally); that driver reads back only what the server refuses on the spot, so no bounce, spam report or service unsubscribe reaches the package and `get_overview` warns about it. Locally, `CAMPAIGNS_DRIVER=fake`, or `smtp` into a mail catcher.
- `CAMPAIGNS_FROM_ADDRESS` on the marketing domain, `CAMPAIGNS_POSTAL_ADDRESS`, `CAMPAIGNS_PRIVACY_URL`, `CAMPAIGNS_ALERT_ADDRESS`, `CAMPAIGNS_TEST_ADDRESSES`.
- Mailgun webhooks to `/campaigns/webhooks/mailgun` with `MAILGUN_WEBHOOK_SIGNING_KEY` (keep it set for a few days after switching drivers, so late events land); an account key in `CAMPAIGNS_MAILGUN_API_KEY` for logs, metrics and suppressions; `php artisan campaigns:import-provider-suppressions <domain>` before the first campaign.
- A worker for the `campaigns` queue with `--timeout` above 300 seconds and the connection's `retry_after` above that; the scheduler running; `CAMPAIGNS_CACHE_STORE` on a store deploys don't flush.
- `php artisan campaigns:token create <name> [--read-only]` for an agent's machine. For OAuth (claude.ai, ChatGPT): Passport with laravel/mcp's `Mcp::oauthRoutes()`, then `'guards' => ['campaigns', 'api']` under `campaigns.mcp` and the gate abilities `campaigns.read` and `campaigns.full`, which the package never defines.
- A CDN's bot or browser check off, and caching off, for the MCP path and its sub-paths, `/campaigns/` (the open pixel `/campaigns/o/` and tracked links `/campaigns/l/` among them: a cached or challenged pixel hides every open), `/oauth/` and `/.well-known/oauth-`.
- `campaigns.mcp.instructions` for the app's own rules for agents: about 2,300 characters fit beside the package's.

## Another sending service

A driver implements `Mozex\Campaigns\Contracts\CampaignDriver` (`send(OutgoingMessage): SendReceipt`, throwing `SendFailed` with `NotAccepted`, `DomainRefused`, `Rejected` (this email refused for good; a `DeliveryFailure::HardBounce` as its fourth argument for a mailbox that doesn't exist) or `Unclear`, decided by status and error code, never the service's words, and no address in the message) plus the abilities its service has in `Contracts\Drivers`: `ReceivesWebhooks`, `ReadsEvents`, `ReadsSuppressions`, `ReadsMetrics`. It turns its service's own open and click tracking off on every email and drops the service's open and click events: the package wraps the links and adds the pixel itself (`OutgoingMessage::$trackOpens` and `$trackClicks` say whether it did). Register it in a service provider's `boot()` with `Campaigns::extend('name', fn (array $config, Application $app) => new NameDriver($config))`; it gets `campaigns.drivers.name` and answers webhooks at `/campaigns/webhooks/name`. Names are lower-case, at most 50 characters; `campaigns` is reserved. `get_overview` says what a missing ability costs.

## Error trackers

Redact: paths under `/campaigns/u/`, `/campaigns/c/`, `/campaigns/v/`, `/campaigns/o/` and `/campaigns/l/` (link and tracking tokens); the `signature` query of `/campaigns/preview/` and of the upload and download links under the MCP path; the body of the upload `PUT`; the body of `POST /campaigns/webhooks/<driver>` (Mailgun's `event-data` field); MCP tool call arguments; outgoing requests to `api.*.mailgun.net/v3/<domain>/bounces`, `/complaints` and `/unsubscribes` (path and query); the `Authorization` header.

## Events

`CampaignStartedEvent`, `CampaignPausedEvent` (`reason`, `automatic`), `CampaignCompletedEvent`, `CampaignQueueStalledEvent`, `CampaignFirstComplaintEvent`, `SubscriberUnsubscribedEvent`, `SubscriberBlockedEvent`, in `Mozex\Campaigns\Events`. They fire after commit; a listener's exception is reported, not thrown.

## Testing

- `CAMPAIGNS_DRIVER=fake` in `phpunit.xml`; `app(DriverManager::class)->get('fake')` is the `FakeDriver` (`Mozex\Campaigns\Sending\Drivers`): `sent()`, `failNext()`, `count($date, $counts)`, `log()`, `suppress()`, `webhook()`, `failReads()` and `readsWork()`. `->swap('fake', new FakeSendOnlyDriver)` tests a driver with no abilities.
- `Queue::fake([SendMessageJob::class])` only, so a started campaign still freezes. Travel time with UTC instants.
- Bind `StaticHostResolver` in place of `HostResolver` so link checks and image fetches never look up real hosts.
- Factories for every model ship with the package: `Subscriber::factory()`, `CampaignList::factory()`, `Campaign::factory()`.
- Assert what an event listener did after the action, never inside it: its failures are reported, not thrown.
