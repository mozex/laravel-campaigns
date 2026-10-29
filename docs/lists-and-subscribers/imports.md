---
title: Imports
weight: 3
---

Moving from another email service means bringing its contacts, opt-outs, blocks and engagement along. `ImportContactsAction` does that from rows you read however the other service exports them, and it's what agents' CSV imports and the Mailgun suppressions import go through too.

## Writing an import command

```php
use Illuminate\Support\Carbon;
use Mozex\Campaigns\Actions\ImportContactsAction;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Imports\ImportedContact;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\ConsentContext;

$contacts = (function () use ($export) {
    foreach ($export->rows() as $row) {
        yield new ImportedContact(
            email: $row['email'],
            name: $row['name'],
            lastOpenedAt: $row['last_open'] ? Carbon::parse($row['last_open']) : null,
            optOut: $row['status'] === 'unsubscribed',
            block: $row['status'] === 'bounced' ? BlockReason::HardBounce : null,
            meta: ['original_list' => $row['list']],
        );
    }
})();

$result = app(ImportContactsAction::class)->execute(
    contacts: $contacts,
    list: CampaignList::forKey('newsletter'),
    context: new ConsentContext('old-service-import', ConsentBasis::Imported, meta: ['by' => 'ops']),
    dryRun: ! $this->option('force'),
    only: $this->option('email'),
);

$this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));
```

- `contacts` is read one row at a time, so a generator or a lazy collection streams a source of any size. A row can be an `ImportedContact` or an array keyed by `ImportedContact::FIELDS`.
- `list` is the static list they join, or `null` to bring in only opt-outs, blocks and engagement. A dynamic list is refused: your records decide who's on it.
- `context` names the import (its `source`) and its basis, `imported` unless you give another. Its `meta` is kept on every consent record and wins over a row's own, so a row can't rewrite who made the change.
- `dryRun` is the real import inside rolled-back transactions, so its counts are the real run's.
- `only` imports one address, to try the run on one contact first.

The result counts each change (`created`, `updated`, `joined`, `already_on_list`, `blocked`, `opted_out`, `kept_suppressed`, `not_found`) and lists problems by row number, never by address, since an agent's chat may show them.

## What an import never does

An import never undoes what your app knows:

- It never clears an opt-out or a block, and never changes a list subscription that exists. Only the person can opt back in.
- It gives a subscriber linked to one of your records no details: the list source owns them, and its next sync would put them back. Other subscribers get only the details and custom fields they lack.
- Engagement dates only move forward.
- An address kept only so it's never mailed (blocked or opted out, on no list) gains nothing but the import's own block or opt-out.

Each row is its own transaction. A row that fails is a problem in the result, and the rest go on. A row whose opt-out or block can be read still keeps it when another of its values fails (a date in a format nobody can read, say): the address is recorded with that alone, with no details and on no list. Values are checked before anything is written (an engagement date before 1970 or in the future, text that isn't UTF-8), because a database error's message would carry the row's address.

## From agents

Agents add up to 100 contacts with `add_contacts`, naming the basis, and up to 2,000 from a CSV through an upload link, with `preview_contacts_import` first and `run_contacts_import` once the person has seen the preview's numbers. Larger lists are a command of your own on the server, like the one above.
