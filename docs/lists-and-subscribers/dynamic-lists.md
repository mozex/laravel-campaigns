---
title: Dynamic Lists
weight: 1
---

A dynamic list follows one of your models: every record of it is on the list, kept in step as records are created, changed and deleted. Your user accounts are the usual one. Nobody is added to or taken off a dynamic list by hand; your records decide.

The other kind, a static list, holds contacts that aren't records of yours: newsletter sign-ups, an imported list. Agents make and manage those ([Subscriptions and Consent](./subscriptions.md)).

## A list source

Implement `Mozex\Campaigns\Contracts\ListSource`:

```php
namespace App\Campaigns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Contracts\ListSource;

class AccountList implements ListSource
{
    public function name(): string
    {
        return 'Accounts';
    }

    public function query(): Builder
    {
        return User::query();
    }

    /** @param  User  $record */
    public function isEligible(Model $record): bool
    {
        return $record->banned_at === null;
    }

    /** @param  User  $record */
    public function fields(Model $record): array
    {
        return [
            'email' => $record->email,
            'name' => $record->name,
            'locale' => $record->locale,
            'country' => $record->country_code,
        ];
    }
}
```

- `query()` returns every record for the sync command to walk, with whatever `fields()` and `isEligible()` read eager-loaded. Leave eligibility out of it: a record's own changes are checked with `isEligible()` alone. Select whole rows.
- `isEligible()` is your own rule, such as a ban. A record that stops being eligible leaves the list and comes back when it's eligible again. Neither is a consent change.
- `fields()` returns the subscriber's details: `email` (required), and any of `name`, `first_name`, `locale`, `timezone`, `country` and `custom_fields`. Only the keys you return are kept in step, so leave out what the record doesn't hold. `custom_fields` merges into what the subscriber already has.

Give the model the trait, and register the source under the list's key:

```php
use Mozex\Campaigns\Concerns\HasCampaignSubscriber;

class User extends Authenticatable
{
    use HasCampaignSubscriber;
}
```

```php
// config/campaigns.php
'list_sources' => [
    'accounts' => App\Campaigns\AccountList::class,
],
```

The key is how campaigns and audiences name the list, so it takes lower-case letters, digits, dots, dashes and underscores. The list itself is created the first time it's needed, named by `name()`. Its people join on `soft_opt_in` (existing customers) unless you record their own choice.

## Bring in the records you have

The trait syncs records from now on. Records that already exist come in once, by hand:

```bash
php artisan campaigns:sync-list accounts
php artisan campaigns:sync-list accounts --id=42 --force
php artisan campaigns:sync-list accounts --force
```

Without `--force` it's a dry run: the real run inside a rolled-back transaction, one record at a time, so its counts are what `--force` would do. Records created before the list existed join on the `legacy` basis, since nobody recorded their consent at the time. Run it again any time to repair what a failure left out.

## Recording a sign-up's choice

When your sign-up form asks about marketing email, create the account under the person's choice:

```php
use App\Models\User;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Support\ConsentContext;

$user = User::withCampaignConsent(
    ConsentContext::fromRequest($request, 'registration', ConsentBasis::Consent, accepted: $request->boolean('newsletter')),
    fn (): User => User::create($attributes),
);
```

A ticked box (`accepted: true`) joins them with their consent recorded, their IP address and browser kept with it. An unticked one (`accepted: false`) records that they declined and opts them out of all marketing. When the form shows a note instead of a box, leave `accepted` out: the record joins on the basis you give, with the notice version and the request kept, and an earlier opt-out of the address stays.

A record that can't join its list when it's created, such as an account whose address isn't confirmed yet, gets no subscriber then, since the address may not be the person's: nothing is linked, moved or deleted on its behalf. A ticked or unticked box is the exception, recorded at once so a later sync never subscribes someone who said no. Wrap the save that makes the record eligible as well, and it joins under that context, with the notice the account was shown and the request that confirmed the address:

```php
User::withCampaignConsent(
    ConsentContext::fromRequest(
        $request,
        'email-confirmation',
        $user->signup_notice === null ? ConsentBasis::Legacy : ConsentBasis::SoftOptIn,
        noticeVersion: $user->signup_notice,
    ),
    fn (): bool => $user->markEmailAsVerified(),
);
```

Here `signup_notice` stands for the version of the sign-up note your app kept for the account; one made before your notice mentioned marketing has none and joins as `legacy`. Only a record that becomes eligible in that save takes the context, and never its `accepted`, so another account saved inside the callback keeps its own consent: record a box ticked on that page with `SubscribeAction` after the save. A list source whose `isEligible()` reads other tables can't show what the save changed, so its record joins as a sync.

Fortify's and Breeze's verification routes call `markEmailAsVerified()` themselves, so put that route behind a middleware that runs the request inside the context:

```php
public function handle(Request $request, Closure $next): Response
{
    $notice = $request->user()?->signup_notice;

    return User::withCampaignConsent(
        ConsentContext::fromRequest($request, 'email-confirmation', $notice === null ? ConsentBasis::Legacy : ConsentBasis::SoftOptIn, noticeVersion: $notice),
        fn (): Response => $next($request),
    );
}
```

A record created without a context joins on the list's default basis from source `staff`, and one made eligible outside a context from source `sync`.

## What happens as records change

- **Created:** the record's subscriber is made (or linked, when the address is already known) and joins the list. A record that isn't eligible yet gets its subscriber once it is, unless its sign-up recorded a choice.
- **Updated:** its details follow `fields()`. A changed address moves the person: their consent history and sent mail go with them, while a block stays with the old address, since a bounce is a fact about an address, not a person.
- **No longer eligible:** off the list, back once eligible again.
- **Deleted:** the person is forgotten ([Privacy and Security](../reference/privacy-and-security.md#forgetting-someone)). An address that was blocked or opted out keeps its row, stripped to the address, with the consent records that prove it, so an import can't bring it back.

The trait hears only Eloquent's model events. A delete that fires none (`User::where(...)->delete()`, a database cascade, a mass prune) leaves the subscriber linked to a record that's gone, still mailable. `campaigns:sync-list` finds those after its walk and forgets them. Forgetting can't be undone, so after such a delete run it without `--force` first and read its "Forgotten" count before running it with `--force`. It forgets nobody while the model's table holds no rows at all, as it would mid-restore.

The sync never breaks your own save. A failure is reported to your error tracker and rolled back to its own savepoint, so a sign-up can't fail because of its marketing list, and `campaigns:sync-list` repairs it later. The one exception is a deadlock or lock timeout inside your transaction: the database has already ended the transaction then, so it's thrown.

One subscriber belongs to one record. A record whose address another record already holds, or that has no valid address, isn't on the list until it has an address of its own.

## Your own activity as engagement

Sign-ins, purchases and the like show a person is still around even when they don't open email. Record them, and they count toward the person's [engagement tier](./engagement.md):

```php
use Mozex\Campaigns\Facades\Campaigns;

Campaigns::recordActivity($user);
```
