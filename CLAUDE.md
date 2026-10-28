# laravel-campaigns

Lets AI agents run a Laravel app's email marketing on the app's own server. The app keeps its lists (dynamic ones fed from its own users, static ones for other contacts), its subscribers and an append-only record of every consent change; campaigns go out one message at a time through Mailgun on the app's own queue, at a pace set per campaign; and the provider's feedback stops addresses that bounce, complain or unsubscribe. Agents reach it through an MCP server the package adds to the app. There are no screens: every operation is a package action, so a later UI can call the same code.

The package is still being built. Today it has the config, the data model, the provider interface, and subscriptions: the consent actions, the consent records, dynamic lists kept in step with the app's records, and the sync command. Sending, feedback, the pages and the MCP server come next.

## Commands

```bash
composer test                  # lint + static analysis + type coverage + tests, the gate before every commit
composer lint                  # fix code style (Pint)
composer test:lint             # check code style without fixing
composer test:types            # PHPStan
composer test:type-coverage    # Pest type coverage (minimum 100%)
composer test:unit             # Pest

vendor/bin/pest tests/SomeTest.php          # one file
vendor/bin/pest --filter="test name here"   # one test
```

## Architecture

- `src/CampaignsServiceProvider.php`: the config (re-merged so a published file picks up new nested keys), the migration stub, the install command (config and migration) and the morph aliases.
- `config/campaigns.php`: the `enabled` switch, the links' base URL, the queue, the cache store for the scheduled commands' locks, whether the package schedules its commands, the fallback pace, the per-domain daily caps and the stop-loss thresholds.
- `database/migrations/create_campaigns_tables.php.stub`: every table, in one stub.
- `src/Models/`: `CampaignList` and `Subscription` (a subscriber's place on a list), `Subscriber` (one address, optionally linked to an app record through the `subscribable` morph), `ConsentRecord` (the ledger), `Campaign`, `CampaignMessage` (one campaign's email to one subscriber), `TestSend`, `ProviderEvent` (what the provider reported) and `ApiToken`.
- `src/Enums/`: the statuses, kinds, bases, actions and reasons the models cast to.
- `src/Contracts/CampaignProvider.php`: the sending provider (send one message, verify and parse webhooks, fetch logged events and suppressions), with its value objects in `src/Sending/`.
- `src/Actions/`: every change to a subscriber, one class each, each writing its consent record: `SubscribeAction` (also the resubscribe, when the person asked), `DeclineAction` (an unticked sign-up box), `ConfirmSubscriptionAction`, `RemoveFromListAction`, `OptOutAction` (what an unsubscribe does), `OptInAction`, `BlockAction`, `UnblockAction`, `ChangeEmailAction`, `ForgetSubscriberAction` (a deleted record) and `SyncRecordAction` (one record onto its dynamic list). A later UI or MCP tool calls these; it never writes the models itself.
- `src/Support/ConsentContext.php`: who changed someone's consent and how (source, basis, notice version, the person's choice, IP and browser), passed to every action. `src/Support/EmailAddress.php`: the one place an address is cleaned and validated.
- `src/Contracts/ListSource.php`, `src/Concerns/HasCampaignSubscriber.php`, `src/Observers/ListSourceObserver.php`: an app record behind a dynamic list (configured in `campaigns.list_sources`), the trait its model uses, and the events that keep the list in step.
- `src/Campaigns.php` (facade `Facades\Campaigns`): the list sources, the dynamic list rows, the consent context a record is created under, and `recordActivity()`.
- `src/Commands/SyncListCommand.php`: `campaigns:sync-list <list>`, a dry run unless `--force`, `--id` for one record.
- `database/factories/`: a factory per model, autoloaded with `src/` so apps can use them in their own tests.

## Key design decisions

- **`laravel/framework`, not the `illuminate/*` components.** The package is shaped like an app (routes, views, mail, queued jobs, the scheduler, signed links), and its jobs, events and pages need the foundation's helpers and traits. Checkers that prefer the split components flag it; it is deliberate.
- **MySQL, MariaDB, PostgreSQL and SQLite.** SQL Server isn't supported: its unique indexes allow a single NULL, and several nullable columns here are unique.
- **One migration stub, no `down()`.** Until the first version is tagged, schema changes edit `create_campaigns_tables.php.stub` in place. After that, every change is a new, additive stub, since apps have already published and run the old one.
- **Every string column has an explicit length** (addresses 254, user agents `text`): apps often lower Laravel's default string length, and a 254-character address must fit. Tests run with a default length of 125, so an unsized column fails the column-length test on MySQL and PostgreSQL.
- **Every foreign key has its own index**, since PostgreSQL adds none, and JSON columns are `jsonb` (`json` on MySQL), since PostgreSQL can't compare plain `json` in a `DISTINCT`.
- **The subscriber's address compares bytes on MySQL and MariaDB** (`utf8mb4_bin`): their default collations make `josé@` and `jose@` one value, which would break the unique index. Addresses are stored lower-cased, so case needs no help from the collation.
- **One subscriber per app record**: the `subscribable` morph pair is unique, so an address change that moves an account to an existing subscriber unlinks the old row first. The morph id column follows the app's `Schema::morphUsingUuids()` or `morphUsingUlids()`, which must be called before the migration runs.
- **`custom_fields`, not `attributes`,** for a subscriber's free-form data: a column called `attributes` collides with Eloquent's own `$attributes` property inside the model.
- **Morph aliases carry a `campaigns.` prefix** and are registered with `Relation::morphMap()`, never `enforceMorphMap()`: apps that enforce a morph map need the aliases, and enforcing is the app's decision.
- **Records outlive their subscriber.** Consent records and campaign messages keep their row with `subscriber_id` nulled when a subscriber is deleted, so what someone agreed to and a campaign's numbers survive; list subscriptions cascade. The address on those messages is personal data, so the code that deletes a subscriber must clear it too.
- **Tokens are stored as SHA-256 hashes only** (`token_hash`, `confirm_token_hash`, `signature_token_hash`) and are `$hidden` on their models; the raw token exists only in the email or response that carries it.
- **Sending is off by default** (`campaigns.enabled`): an app installs the package, records consent and builds its lists before anything can send.
- **A message never moves back from `sending`**, since the provider may already have the email; the one step back is `queued` to `pending`, for a claim whose job never ran (`MessageStatus`). A campaign holds one message per subscriber (a unique index on the pair).
- **One app-wide opt-out.** A person's unsubscribe (and an unticked sign-up box) sets the subscriber's `opted_out_at`, which stops all marketing whatever lists they are on; their list subscriptions stay, for targeting and history. A block (`blocked_at`) is a fact about the address, never cleared by anything but `UnblockAction`.
- **Only the person clears an opt-out**: a context with `accepted: true` (a ticked box, the switch, subscribing again) or a confirmed double opt-in. A sync, an import or an agent adding a contact never does: `SubscribeAction` leaves an existing subscription and the opt-out as they are.
- **List statuses**: `pending` waits for a double opt-in; `unsubscribed` means a dynamic list's record isn't eligible right now (it flips back when it is, with no consent record, since eligibility isn't consent). An agent taking someone off a list deletes the row, so adding them again starts afresh.
- **Every state change is a conditional update** (`whereNull('opted_out_at')` and so on), and its consent record is written only when the update changed a row, so two requests racing never record one change twice.
- **Leaving keeps a suppressed address.** A deleted record, or an address the person moved away from, keeps its row stripped to the address when it is blocked or opted out, so a later import can't mail it again; any other address is deleted. Either way the person's details, IPs, browsers and the addresses on their sent mail go, and their pending mail is skipped (`ForgetSubscriberAction`).
- **An address change moves the person, not the block** (`ChangeEmailAction`): in place when the old row holds nothing to keep; otherwise the person moves to the new address's row (made, or one that exists, the stricter list status winning), their consent records follow them except block and unblock, and the old row is forgotten. The record link moves first, since the morph pair is unique.
- **The observer never throws**, but for one case. A failure syncing a record (a broken list source in the config included) is reported and rolled back to its own savepoint, so an app's sign-up can't fail because of its marketing list; `campaigns:sync-list` repairs what was missed. The exception is a deadlock or lock timeout inside the app's transaction: Laravel rolls back no savepoint then and the database has ended or aborted the whole transaction, so it is rethrown.
- **A consent context is scoped** to one model class and one callback (`Model::withCampaignConsent()`), restored in `finally`, and the manager is a scoped binding, so it can't leak into the next record, request or job. A record made without one counts as made by staff.
- **Records older than their list are legacy.** The dynamic list's row is created the first time it is needed, and the sync gives records created before it the `legacy` basis.
- **The sync's dry run is the real run rolled back**, one transaction per record, so its counts are what `--force` would do (two records sharing an address both count as new in a dry run).
- **Rows other requests may make at the same moment go through `Support\Rows::firstOrCreate()`**, never Eloquent's `firstOrCreate()`/`createOrFirst()`: after a lost insert race those read the row with a plain select, which inside a MySQL transaction returns the snapshot taken before the row existed, and throw. `Rows` reads it with `lockForUpdate()`.
- **A record that can't be synced leaves its list**: no valid address, or an address another record holds, takes the subscription to `unsubscribed` like ineligibility, so the address its subscriber still holds isn't mailed.
- **A choice made at creation is recorded even when the record isn't eligible yet** (it joins, then leaves until eligible), so a later sync never subscribes someone who left the box unticked.
- **Agents can't take anyone off a dynamic list** (`RemoveFromListAction` refuses): the record's next save would put them back. The app's records and `isEligible()` decide it.

## Extension points

- `Mozex\Campaigns\Contracts\CampaignProvider`: the sending provider. Mailgun is the planned driver.
- `Mozex\Campaigns\Contracts\ListSource`: an app model as a dynamic list (`campaigns.list_sources`), with `isEligible()` for the app's own rule and `fields()` for the details kept in step.
- `Campaigns::recordActivity($record)`: the app's sign-ins, purchases and the like, as engagement.
- The actions resolve through the container, so an app can bind a subclass.

## Testing

- `TestCase` runs the migration stub on Testbench's in-memory SQLite before each test, with foreign keys turned on (Testbench leaves them off; Laravel's own SQLite config turns them on) and strict models (apps commonly run them).
- `useDatabase($driver)` in `tests/Pest.php`, with the `databases` dataset (`sqlite`, `mysql`, `pgsql`), moves a test onto a real server's `campaigns_testing` database: it creates that database if needed, drops every table in it and runs the stub. Locally it skips when no server answers; on GitHub Actions it fails instead, since CI starts both servers. The defaults match CI's service containers; `CAMPAIGNS_TEST_MYSQL_*` and `CAMPAIGNS_TEST_PGSQL_*` (`HOST`, `PORT`, `USERNAME`, `PASSWORD`) point at others.
- Anything database-specific (claims, locks, column lengths, JSON) gets the `databases` dataset.
- The install tests delete the config and migration files they publish into Testbench's app, before and after each test.
- `tests/Fixtures/Member` (a `members` table, created in `TestCase::runMigrations()` so the real-server runs get it too) and `MemberList` stand in for an app's accounts and their list source; PHPStan analyses the fixtures, since it checks a trait only through a class that uses it.

## Development notes

- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- The PHP floor is 8.2: no typed class constants (`public const string X`) and no `new Foo()->bar()` without wrapping parentheses. PHPStan's `type_coverage.constant` is 0 for that reason.
- Larastan reads columns only from `.php` migrations, never the `.php.stub`, so each model lists its columns as `@property` lines. A new column gets its line in the same change, or code reading it fails PHPStan.
- Read `campaigns.domain_daily_caps` as a whole map: its keys are domains, and the dots in them make `config('campaigns.domain_daily_caps.'.$domain)` find nothing.
- Eloquent's `Table`, `Fillable` and `Hidden` attributes don't exist on the Laravel 12 floor, so models set `$table`, `$fillable` and `$hidden` as properties. `UseFactory` does exist there.
