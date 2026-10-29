---
title: Subscriptions and Consent
weight: 2
---

## Subscribers, lists and the ledger

A subscriber is one email address, linked to one of your records when a dynamic list knows it. A subscription puts a subscriber on a list, as `subscribed`, `pending` (waiting for a double opt-in) or, on a dynamic list, `unsubscribed` while their record can't be on it.

Two facts sit on the subscriber, not on any list:

- **Opted out** (`opted_out_at`): the person asked for no marketing. One unsubscribe stops every campaign, whatever lists they're on; their subscriptions stay, for targeting and history.
- **Blocked** (`blocked_at`, with a reason: `hard_bounce`, `complaint`, `soft_bounces`, `manual`, `imported`): the address mustn't be mailed. A block is about the address, so it stays when the person moves to a new one.

Every change is a row in the consent ledger (`ConsentRecord`): what changed, on which list, its source, its legal basis, the notice version the person saw, and, when the person acted themselves, their IP address and browser. Records are never edited and survive the subscriber being deleted, so you can answer a complaint about any email you ever sent.

## The actions

Change subscribers only through the actions in `Mozex\Campaigns\Actions`. Each one writes its ledger record, and each is a conditional update, so two requests racing never record one change twice.

| Action | Does |
|---|---|
| `SubscribeAction` | puts a subscriber on a list, `pending` on a double opt-in list; an existing subscription is left as it is |
| `DeclineAction` | records an unticked sign-up box: on the list, but opted out |
| `ConfirmSubscriptionAction` | confirms a pending double opt-in |
| `RemoveFromListAction` | takes someone off a static list |
| `OptOutAction`, `OptInAction` | opts the person out of everything, or back in |
| `BlockAction`, `UnblockAction` | blocks or unblocks the address |
| `ChangeEmailAction` | moves the person to a new address |
| `ForgetSubscriberAction` | forgets the person ([Privacy and Security](../reference/privacy-and-security.md#forgetting-someone)) |
| `SendConfirmationAction` | queues a pending double opt-in's confirmation email |

Each takes a `Mozex\Campaigns\Support\ConsentContext` saying who changed what and why:

```php
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Support\ConsentContext;

ConsentContext::fromRequest($request, 'newsletter-form', ConsentBasis::Consent, noticeVersion: '2026-01', accepted: true);
new ConsentContext('staff', meta: ['by' => $admin->name]);
```

`accepted` is what the person chose when they chose: `true` when they asked for email, `false` when they left the box unticked, `null` when the app or an agent acted for them. **Only `true` clears an opt-out**, or a confirmed double opt-in: a sync, an import or an agent never brings back someone who left.

The legal bases are `consent` (they asked), `soft_opt_in` (an existing customer, where the law allows marketing to them), `imported` (from another system) and `legacy` (on a list before consent was recorded).

## A newsletter form

A static list holds contacts that aren't records of yours. Agents create them with `create_list`, or your code can with `CreateListAction` (`key`, `name`, `description`, `doubleOptIn`, `basis`). A sign-up form puts the visitor on one:

```php
use Mozex\Campaigns\Actions\SendConfirmationAction;
use Mozex\Campaigns\Actions\SubscribeAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

$request->validate(['email' => ['required', 'email', 'max:254']]);

$subscription = app(SubscribeAction::class)->execute(
    Subscriber::forEmail($request->input('email')),
    CampaignList::forKey('newsletter'),
    ConsentContext::fromRequest($request, 'newsletter-form', ConsentBasis::Consent, noticeVersion: '2026-01', accepted: true),
);

app(SendConfirmationAction::class)->execute($subscription);
```

- `Subscriber::forEmail()` cleans the address and returns its subscriber, making one if the address is new. Two sign-ups racing get the same row.
- Someone already on the list stays as they are. Because the person asked (`accepted: true`), an earlier opt-out is cleared; a block never is.
- On a list without double opt-in they're subscribed at once and `SendConfirmationAction` does nothing. On a double opt-in list it mails the confirmation link.

## Double opt-in

On a list made with `doubleOptIn: true`, a new subscriber waits as `pending` and gets nothing until they confirm. `SendConfirmationAction` queues the email with their confirmation link:

- an address gets at most one confirmation email a day, whichever list asks, and a blocked address none;
- an opted-out person still gets it, since confirming is their way back in;
- nothing goes out while sending is switched off, so call it again for sign-ups made then;
- each email carries a new link, and the earlier one stops working;
- when the person's address changes while they wait, the link already sent stops working, since it proved the old address, not the new one: send a new confirmation to the new address.

Opening the link shows a page with a button; only pressing it confirms, since link scanners open every link in an email. Contacts an agent adds to a double opt-in list wait as `pending`, and no tool sends them the email: an agent's list of addresses never mails people who didn't ask.

## The pages

Every email's links open pages the package serves, outside your `web` middleware:

| Page | Address |
|---|---|
| Unsubscribe and preferences | `/campaigns/u/{token}` |
| Double opt-in confirmation | `/campaigns/c/{token}` |
| Web version | `/campaigns/v/{token}` |

The unsubscribe page shows the person's state: subscribed people can unsubscribe from everything, opted-out people can subscribe again (from the address they still hold, never a blocked one), and a blocked or forgotten address has nothing to do. Mail apps' one-click unsubscribe (the `List-Unsubscribe` header, RFC 8058) posts to the same address and gets a plain 200.

A link shows the address its email went to, never the person's current one, so whoever reads an old address can't learn the new one.

The pages speak the subscriber's language when the package has strings for it, and otherwise your app's. Publish the views and strings to restyle them and add languages:

```bash
php artisan vendor:publish --tag=campaigns-views
php artisan vendor:publish --tag=campaigns-translations
```

`campaigns.name` (default `APP_NAME`) is what they call your app.
