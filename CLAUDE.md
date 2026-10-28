# laravel-campaigns

Lets AI agents run a Laravel app's email marketing on the app's own server. The app keeps its lists (dynamic ones fed from its own users, static ones for other contacts), its subscribers and an append-only record of every consent change; campaigns go out one message at a time through Mailgun on the app's own queue, at a pace set per campaign; and the provider's feedback stops addresses that bounce, complain or unsubscribe. Agents reach it through an MCP server the package adds to the app. There are no screens: every operation is a package action, so a later UI can call the same code.

The package is still being built. Today it has the config, the data model and the provider interface; the actions, sending, feedback, pages and the MCP server come next.

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
- `database/factories/`: a factory per model, autoloaded with `src/` so apps can use them in their own tests.

## Key design decisions

- **`laravel/framework`, not the `illuminate/*` components.** The package is shaped like an app (routes, views, mail, queued jobs, the scheduler, signed links), and its jobs, events and pages need the foundation's helpers and traits. Checkers that prefer the split components flag it; it is deliberate.
- **One migration stub, no `down()`.** Until the first version is tagged, schema changes edit `create_campaigns_tables.php.stub` in place. After that, every change is a new, additive stub, since apps have already published and run the old one.
- **Every string column has an explicit length** (addresses 254, user agents `text`): apps often lower Laravel's default string length, and a 254-character address must fit. The column-length test runs on MySQL and PostgreSQL, where a short column fails.
- **`custom_fields`, not `attributes`,** for a subscriber's free-form data: a column called `attributes` collides with Eloquent's own `$attributes` property inside the model.
- **Morph aliases carry a `campaigns.` prefix** and are registered with `Relation::morphMap()`, never `enforceMorphMap()`: apps that enforce a morph map need the aliases, and enforcing is the app's decision.
- **Records outlive their subscriber.** Consent records and campaign messages keep their row with `subscriber_id` nulled when a subscriber is deleted, so what someone agreed to and a campaign's numbers survive; list subscriptions cascade.
- **Tokens are stored as SHA-256 hashes only** (`token_hash`, `confirm_token_hash`, `signature_token_hash`) and are `$hidden` on their models; the raw token exists only in the email or response that carries it.
- **Sending is off by default** (`campaigns.enabled`): an app installs the package, records consent and builds its lists before anything can send.
- **A message only moves forward** through `MessageStatus`, and a campaign holds one message per subscriber (a unique index on the pair).

## Extension points

- `Mozex\Campaigns\Contracts\CampaignProvider`: the one swap point so far. Mailgun is the planned driver.

## Testing

- `TestCase` runs the migration stub on Testbench's in-memory SQLite before each test, with foreign keys turned on (Testbench leaves them off; Laravel's own SQLite config turns them on) and strict models (apps commonly run them).
- `useDatabase($driver)` in `tests/Pest.php`, with the `databases` dataset (`sqlite`, `mysql`, `pgsql`), moves a test onto a real server's `campaigns_testing` database: it creates that database if needed, drops every table in it and runs the stub. Locally it skips when no server answers; on GitHub Actions it fails instead, since CI starts both servers. The defaults match CI's service containers; `CAMPAIGNS_TEST_MYSQL_*` and `CAMPAIGNS_TEST_PGSQL_*` (`HOST`, `PORT`, `USERNAME`, `PASSWORD`) point at others.
- Anything database-specific (claims, locks, column lengths, JSON) gets the `databases` dataset.
- The install tests delete the config and migration files they publish into Testbench's app, before and after each test.

## Development notes

- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- The PHP floor is 8.2: no typed class constants (`public const string X`) and no `new Foo()->bar()` without wrapping parentheses. PHPStan's `type_coverage.constant` is 0 for that reason.
- Eloquent's `Table`, `Fillable` and `Hidden` attributes don't exist on the Laravel 12 floor, so models set `$table`, `$fillable` and `$hidden` as properties. `UseFactory` does exist there.
