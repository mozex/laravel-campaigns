# laravel-campaigns

Lets AI agents run a Laravel app's email marketing on the app's own server, through an MCP server the package adds. The app keeps its lists (dynamic ones fed from its own models, static ones for other contacts), its subscribers and an append-only consent ledger; campaigns go out one message at a time through a driver (Mailgun's by default) on the app's queue at a pace set per campaign; the service's feedback stops addresses that bounce, complain or unsubscribe, and a stop-loss pauses campaigns past their limits. There are no screens: every write is a package action (`src/Actions/`), and the MCP tools only call them, so a later UI calls the same code. Public docs: `docs/` (published on mozex.dev) and the README; the Boost skill and guideline for integrators: `resources/boost/`; what agents read: `resources/mcp/instructions.md` and `guide.md`.

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

- `src/CampaignsServiceProvider.php`: config (merged key by key, so an older published file gets new nested keys), views, translations, both route files, the migration stub, the install command, commands, morph aliases, the `campaigns` guard, the alert listener, the `campaigns.links` limiter and the schedule. `config/campaigns.php` documents every key.
- `database/migrations/create_campaigns_tables.php.stub`: every table. `database/factories/`: one per model, autoloaded with `src/` for apps' tests.
- `src/Models/`: lists, subscriptions, subscribers, the consent ledger, campaigns and their messages, test sends, provider events, webhook tokens, API tokens, templates, revisions and their per-locale contents, assets, uploads, saved audiences.
- `src/Actions/`: every change, one class each. Consent (`SubscribeAction`, `DeclineAction`, `OptOutAction`, `OptInAction`, `BlockAction`, `UnblockAction`, `ChangeEmailAction`, `ForgetSubscriberAction`, `SyncRecordAction`, `UnsubscribeAction`, `ResubscribeAction`, `ConfirmSubscriptionAction`, `SendConfirmationAction`), imports and lists, templates and revisions, uploads and images, campaigns (create, update, duplicate, start, freeze, pause, resume, cancel, pace), feedback (`RecordFeedbackAction`, `ApplyFeedbackAction`, `EnforceStopLossAction`), `PumpCampaignsAction`, `ReconcileCampaignsAction`, pruning, tokens and preview links.
- `src/Content/`: an email's content, the revision rules (`ContentRules`), placeholders and the renderer, plain text, HTML reading (`Html`), UTM tags. `src/Templates/` and `resources/templates/`: the starter templates.
- `src/Uploads/`, `src/Assets/`, `src/Fetching/`, `src/Checks/`: emails uploaded as HTML or a zip, the image library, the one guard every server-side fetch passes, the pre-send checks.
- `src/Audiences/`: the built-in filters, a campaign's audience definition, the one query behind preview, count and freeze, and the preview.
- `src/Sending/`: the driver contract's value objects, `MessageComposer`, `Pace`, `Allowance`, `StopLoss`. `src/Sending/Drivers/`: `DriverManager` (builds drivers by name from `campaigns.driver` and `campaigns.drivers.<name>`, and those registered with `Campaigns::extend()`), `MailgunDriver`, `SmtpDriver`, `FakeDriver` and the narrower fakes over the `FakesDriver` trait, and `UsesCampaignsMailer` (the campaigns mailer's transport check). `src/Contracts/CampaignDriver.php` (send only) and `src/Contracts/Drivers/` (the optional abilities).
- `src/Jobs/`: freeze, send, test send, confirmation, feedback, stop-loss and checks jobs. `src/Commands/`: pump, reconcile, prune, sync-list, suppressions import, token.
- `src/Reports/`: the campaign report and the cut summaries tools answer with.
- `src/Mcp/`: `CampaignsServer`, `Principal`, `Tools/` (48 tools on `CampaignsTool`, shared answer shapes in `Schemas`), `Resources/` (the guide and the app's resources). `routes/mcp.php`: the server, and the signed upload and download links (`Http\Controllers\AgentFileController`) under its path.
- `routes/campaigns.php`, `src/Http/`: the pages an email's links open (`/campaigns/u/`, `/c/`, `/v/`), the signed preview page and the webhook route per driver (`/campaigns/webhooks/{driver}`). Views and English strings in `resources/`.
- `src/Contracts/`: `ListSource`, `Audience`, `AgentResource`, `CampaignDriver` and `Drivers\` (`ReceivesWebhooks`, `ReadsEvents`, `ReadsSuppressions`, `ReadsMetrics`). `src/Campaigns.php` (facade `Facades\Campaigns`) resolves them and the consent context. `src/Events/`, `src/Listeners/`, `src/Mail/`: events, the alert mail, the confirmation mail.
- `src/Support/`: tokens, links, the sender, keys, text values (`TextValue`, `ShownText`), the consent context, address cleaning (`EmailAddress`), engagement, race-safe `Rows::firstOrCreate()`, after-commit dispatch.

## Key design decisions

Each class's docblock gives the reasons for its own behaviour; read it before changing the class. These are the rules a session would otherwise undo, most crossing several classes.

### Schema and databases

- `laravel/framework`, not `illuminate/*` components: the package is app-shaped (routes, views, mail, jobs, scheduler, signed links). A dependency checker's warning about it is deliberate.
- MySQL, MariaDB, PostgreSQL and SQLite; never SQL Server (its unique indexes allow one NULL).
- One stub, no `down()`. Until the first tag, schema changes edit the stub in place; after it, every change is a new additive stub.
- Every string column has an explicit length (addresses 254); tests run at a default string length of 125 so an unsized column fails. Every foreign key has its own index; JSON columns are `jsonb`. A NOT NULL timestamp gets `useCurrent()` (MySQL without `explicit_defaults_for_timestamp`).
- The address is `utf8mb4_bin` on MySQL and MariaDB (`josé@` and `jose@` are one value otherwise), stored lower-cased through `EmailAddress`.
- Morph aliases carry a `campaigns.` prefix through `Relation::morphMap()`, never `enforceMorphMap()`. The subscribable morph follows the app's `morphUsingUuids()`; the revision's owner morph is an integer column, since its owners are the package's own models.
- `custom_fields`, never `attributes` (Eloquent's own property). Models set `$table`, `$fillable` and `$hidden` as properties and list their columns as `@property` lines (Larastan can't read the stub).
- Consent records and campaign messages outlive their subscriber (`subscriber_id` nulled); code deleting a subscriber clears the address on its messages.
- Rows other requests may make at the same moment go through `Support\Rows::firstOrCreate()`, which re-reads with `sharedLock()`: Eloquent's `firstOrCreate()` reads a stale MySQL snapshot after a lost race, and `lockForUpdate()` deadlocks two losers.
- Every state change is a conditional update, and its consent record is written only when it changed a row.
- A database error's message carries the SQL's bindings, addresses among them: values are checked before the write, and an error that still happens is rethrown with its SQLSTATE only, never chained, at every entry point that writes a person's details (`Support\DatabaseErrors`: the feedback job, the pages' POSTs, the webhook, `CampaignsTool::handle()`, the sync and import loops, the freeze, the observer). Catch `PDOException`: a `DeadlockException` is no `QueryException`. The scrubbed error is a `DatabaseFailedException` (a `CampaignsException`, so a catch that answers refusals must report it), or, for a deadlock an outer transaction must retry, a new `DeadlockException` with code 40001.

### Consent and subscribers

- Writes only through actions; each writes its consent record. App code makes a subscriber for an address only through `Subscriber::forEmail()` (cleaned, race-safe); `SubscribeAction` refuses a context with `accepted: false`, which is `DeclineAction`'s. One app-wide opt-out (`opted_out_at`) stops all marketing whatever the lists; a block (`blocked_at`) is a fact about the address, cleared only by `UnblockAction`.
- Only the person clears an opt-out: a context with `accepted: true` or a confirmed double opt-in. A sync, an import or an agent never does (`ImportContactsAction` drops `accepted`).
- Nobody is added to or removed from a dynamic list by hand; the app's records and `isEligible()` decide. A dynamic list's `unsubscribed` status means its record can't be on it now (not eligible, or no address it can hold); no consent record either way.
- The observer never breaks the app's save: failures are reported and rolled back to a savepoint; a deadlock or lock timeout is rethrown, scrubbed, since the database ended the transaction. The consent context is scoped to one model class and one callback (`withCampaignConsent()`), restored in `finally`; a record made without one counts as made by staff, one older than its list as `legacy`.
- A choice made at creation is recorded even when the record isn't eligible yet.
- Leaving keeps a suppressed address stripped to the address (`ForgetSubscriberAction`); other addresses are deleted, and the person's details, IPs, browsers and sent mail's addresses go.
- An address change moves the person, not the block (`ChangeEmailAction`): consent records except block and unblock follow, sent mail moves with the old address on it, engagement merges, soft bounces start at 0, and the old row's waiting double opt-in links are voided (they proved the old address; the new address's own stay), so such a wait never outranks the new address's subscription.
- `campaigns:sync-list` forgets subscribers whose record went without a model event (`forgetOrphans()`: the table read without global scopes, a soft-deleted record counting as gone, skipped for a model on another connection or a table with no rows at all, since forgetting can't be undone).
- A list source's custom fields meet `SetCustomFieldsAction::check()`; refused ones are reported and left out, never stopping the record's eligibility or address syncing.
- Imports never undo what the app knows (`ImportContactsAction`): no cleared opt-out or block, no changed subscription, no details for a record-linked subscriber, engagement only forward, the context's meta winning over a row's; a row whose other values fail still records its opt-out or block (`ImportedContact::suppressionOf()`), and details that aren't UTF-8 are refused before cleaning would turn them into "?". One transaction per row through `transaction()`, a dry run throwing `RolledBackRow`; the first read is a locking read by address. Problems name rows, never addresses.
- Tiers and the sunset are worked out, never stored (`Support\Engagement`), PHP and SQL side by side and tested to agree. The queries compare each column on its own, never `GREATEST()` or a `NOT` over NULLs.
- Filters target; the `mailable` scope decides who may be mailed. An unknown filter, list or campaign key is refused rather than matching nobody.
- `SubscriberUnsubscribedEvent` and `SubscriberBlockedEvent` fire after commit through `Support\AfterCommit`, which reports a listener's failure rather than throwing it, carrying a subscriber loaded on its own.

### Content, uploads and files

- A revision never changes once saved (only its check results): numbered under a lock on its owner's row with a locking read of the last number; restoring adds a copy. A campaign's email changes only while it is a draft. A revision is always found through its owner (`NamesEmailOwner`).
- Placeholders are a fixed set, replaced in one pass, never compiled (`Content\Placeholders`); values escaped in HTML, raw in text; a subject or preheader takes only the personal three, control characters as spaces.
- `ContentRules` reports every problem at once: at most 20 locales, the default present (`campaigns.locale`, filled from `app.locale` at registration), unknown double-brace text refused, an unsubscribe link in HTML and text, every image on https, elements nested at most `Html::MAX_DEPTH` (200) deep (libxml stops reading at 256 and drops the rest, blinding every DOM rule; the margin is the footer's), 90 KB of rendered HTML and of text (HTML over twice that as written is refused unrendered).
- Images are read by `Content\ImageSources`, a forward scanner with offsets, never the DOM: the https rule, the `images` check, an upload's relative paths, its unused images and its rewrite all read that one list, so they agree. It reads Outlook's conditional comments' inner HTML (never plain comments; a condition opened inside one is read in place, never read again on its own, which took gigabytes), and only the element and attribute pairs in its docblock (`input src` only for `type=image`, `source` only `srcset`, `href` only on `image`). Comments end (`<!-->`, `--!>`), references decode (`&#13`, a bare `&quot`) and CSS escapes count as HTML5 has them; what it leaves unread on purpose is listed in its docblock. Every search runs forward from the last, never a lazy pattern, which ran out of PCRE's backtrack limit and read nothing.
- Other HTML is read through the DOM but changed as a string (`Content\Html`): libxml percent-encodes braces in `href` and `src`, so the rules refuse `%7B%7B...%7D%7D` and triple braces. A srcset is read as the spec reads it (`Html::srcset()`: commas inside an address stay), a CSS `url(#...)` loads nothing. HTML and text columns are `mediumText`.
- The hidden preheader goes where the parser starts the body (`Renderer::bodyStart()`), never before a doctype or inside the head. Locales compare normalized everywhere (`Locales::normalize()`), uploads' subjects and the default-language rule included.
- Every server-side fetch of an agent's address goes through `Fetching\Fetcher`: public addresses only on every hop, the connection pinned with `CURLOPT_RESOLVE`, no proxy (the proxy option the empty string), redirects followed by the fetcher, the body capped and never decoded. Pass no curl option outside Guzzle 8's allow-list, and never Guzzle's `stream` option.
- Assets are named by content, typed by their bytes, never SVG, never deleted. Uploads wait on a private disk; a link takes one file within 15 minutes, counted as bytes arrive, and its tool claims it once; the exception's code is the endpoint's status. A zip is read in memory, never unpacked to disk, each file to its declared size.
- Everything checkable is checked before an upload is read, since reading uses it up. An upload's limit is capped by `post_max_size` (`Upload::maxSize()`), which Laravel's `ValidatePostSize` applies to a PUT too.
- Pre-send checks report and never block; queued, stored only while their run is the latest.
- Starter templates are files with reserved keys. An archived template or list is hidden, not closed to the app.

### Sending

- Sending is off by default (`campaigns.enabled`); the actions refuse starting, scheduling, resuming, speeding up and test emails while off, while pausing, slowing down and cancelling never wait. The tools refuse only what the actions refuse.
- Starting is a claim, then a queued freeze (`FreezeCampaignAction`): one transaction for the frozen email, the messages and the status; a recipient count other than the approved one rolls back to a draft with `start_error`. Each start has a run id its job carries. The pump hands back a start left `starting` too long.
- One query behind the preview, the count and the freeze (`CampaignAudience`), through subqueries, never joins. A stored audience holds subscriber ids, never addresses; an explicit empty set reaches nobody.
- Nothing a tool answers reads a model per row: the preview counts in the database and reads only addresses, the report reads plain rows, since hydrating outlasts a request at hundreds of thousands. The freeze reads models and works tiers out through `Engagement::tiers()`, its cutoffs computed once.
- The send order is `position`: tier, then rank times provider count plus provider, so providers take turns.
- The pump claims, then hands to the queue; only rows carrying its claim id get a job. The allowance is counted from the messages, never the cache; the pace's state is in columns. The pump's last run is the one cache value, shown only.
- The send job takes its message from `queued` to `sending` in one conditional update (fresh claim, campaign sending, sending on). A message steps back only when the provider took nothing; an unclear answer is `unknown`, never resent, halves the rate, and `UNCLEAR_LIMIT` of them within `UNCLEAR_MINUTES` pause the campaign (`SendMessageJob::unclear()`). A `Rejected` send fails, never resent (`SendMessageJob::rejected()`): one carrying a hard bounce gets `sent_at` (the stop-loss counts bounces among sends), records a failed event of the driver's own after the message is marked, whose effects block the address, and runs the stop-loss itself, since that event finds the message already failed; any other is `rejected`, address untouched, and the campaign pauses when its last `REJECTED_LIMIT` sends by `sending_at` were all `rejected`, whatever the time. The service's answer is read by transport fact, status and error code, never its words (`MailgunDriver::failure()`); `SendFailed` never holds an address or chains the transport's exception.
- A driver must only send (`CampaignDriver`); every other ability is an interface in `Contracts\Drivers` that each caller checks with `instanceof`, saying why when it's missing, never reading "can't" as "nothing there" (the reconciler skips, the suppressions import refuses, the unblock check answers `checked: false`, the overview notes it). Nothing outside `Sending\Drivers\` names Mailgun (`DriverManagerTest` greps `src/`).
- A driver with neither `ReceivesWebhooks` nor `ReadsEvents` reads nothing back (`DriverManager::readsBack()`): its emails end at `sent`; the report divides opens and clicks by them (`rates.basis`, its delivery rate leaving them out), a driver not configured or not buildable counting as one; the scanner rule times from `sent_at` without a delivery; the reconciler makes a `sending` row of any driver whose logs it doesn't read `unknown` after `LOOK_AFTER`, unlooked-up (`settleUnread()`), so its campaign completes; `get_overview` gives one combined warning in place of the two ability notes, and `start_campaign` and `resume_campaign` answer it as `warning`.
- Drivers come from the `DriverManager` singleton (`Campaigns` is scoped, so a creator kept there would be lost); `CampaignDriver::class` is bound with `bind()` to the active one. A custom creator gets its `campaigns.drivers.<name>` block and the app; names match `DriverManager::NAME_PATTERN` (a path segment, the columns' 50) and `campaigns` is reserved for the package's own opens and clicks. A class name in the config isn't read. Tests swap with `DriverManager::swap()`.
- Every email goes through the active driver, the double opt-in confirmation included (no unsubscribe header, untracked, an unstored uuid, tag `campaigns-confirmation`); only the alert uses the app's default mailer.
- The send job's claim writes the active driver's name on the message or test send; events are unique on (`provider_event_id`, `driver`), never null, and every lookup by event id or the service's message id also names the driver.
- A driver is configured when active, registered with `extend()`, or holding its webhook credential in its block (the fake only when active); only then does `/campaigns/webhooks/{driver}` answer, or the reconciler read its logs, and an unconfigured one is never built. The reconciler reads per driver among a domain's messages, each with its own `logDays()`, its bookmark moving only to the least point reached.
- The Mailgun driver sends only through `MailgunApiTransport`; every message sets both tracking options. A mailgun campaigns mailer without `client` options gets 10-second idle and 30-second total timeouts at registration (`DriverManager::boundMailer()`), before any mailer is built, never by rebuilding one. Its config is read on every call, since the manager keeps the driver. The fake refuses production (`DomainRefused`).
- The SMTP driver drives the campaigns mailer's `EsmtpTransport` only (log and array outside production) and reads reply codes and the command they answered (the last client line of the exception's debug log), never the server's words: before each send it turns off Symfony's restart and ping (`setRestartThreshold()`, `setPingThreshold()`: the restart runs after a send went, outside Symfony's try, so a refused reconnect read as that email's failure), calls `start()` (idempotent, since `SmtpTransport::$started` is private; a 5xx there is `DomainRefused`, a 4xx or none `NotAccepted`), then `NOOP`, restarting a connection that fails it. Never `hasPendingData()`: Symfony added it in 7.4.19 and 8.1.7, under the versions the package allows. While sending: 4xx `NotAccepted`; 5xx to MAIL FROM `DomainRefused`; 5xx to RCPT or the end of data `Rejected`, a hard bounce for RFC 3463's 5.1.1, 5.1.2, 5.1.3, 5.1.10 (read from the reply Symfony quotes); any other 5xx `Rejected`; no code `Unclear`; Symfony's `InvalidArgumentException` (a non-ASCII local part without SMTPUTF8, thrown before MAIL FROM) `Rejected`. It strips Laravel's `X-Tag` and `X-Metadata-*`. A smtp campaigns mailer whose `timeout` is null (Laravel's default) gets 10 seconds at registration (`boundMailer()`). Only `DeliveryFailure::HardBounce` on a `SendFailed` takes the send job's bounce path.
- Links start with `campaigns.url`, never the request's host. Tokens are stored as SHA-256 hashes only; the raw token exists only in the email or response that carries it, and a queued job carries ids, never a token.
- Events a pump or job dispatches carry a model loaded on its own (strict models refuse lazy loading on a list's rows).
- The pump is scheduled with `withoutOverlapping(5)` on `campaigns.cache_store` through its own mutex; the reconciler and pruning run in the background.

### Feedback, stop-loss and reconciliation

- A webhook is verified by HMAC in constant time, and its token hash is recorded with its event id in the same transaction, so a replay is refused across a cache flush. Stale or bad requests answer 406; errors 500.
- An event is stored once by the service's id and the driver, finds its email by the package's uuid (checked with `Str::isUuid()` first), keeps no address or IP, and is applied once under a row lock with `processed_at`. Effects read only the stored event and move forward only.
- `MailgunDriver::deliveryFailure()` is the one place Mailgun's words are read; counts come from the message's `failure_reason`, never the event.
- Gmail's image proxy is a person; every other machine is a bot. Clicks on the package's own pages never count as engagement.
- The stop-loss measures from messages, never events: per send, "reached" at the value and "passed" above it, a limit of 0 meaning the first one. The domain is held to the config's limits. Each pause writes a floor (`limits_reset_at`, `domain_limits_reset_at`) so a resume isn't paused again by the same numbers. It runs outside the feedback job's transaction and never throws.
- The alert goes through the app's default mailer, sent at once, never queued or through the campaigns mailer.
- The provider's logs and lists are read with their own key, never chained or quoted on failure. The Logs API is read oldest first, never nearer than half an hour for a replay, never past `logDays()`. A lookup that can't tell answers null, never an empty list.
- The reconciler writes only conditionally, runs whatever `campaigns.enabled` says, stores and reads within per-run limits, and keeps its bookmark at the last batch it handled. Its domain replay asks for every type but `accepted` (half a busy domain's volume); a message's own lookup asks for it too.
- Failures that reach `failed_jobs` or the error tracker never carry an address: a mailer's refusal of the confirmation email is rethrown scrubbed and unchained, and the subscriber events use `SerializesModels`.
- Old events go only once their campaign's report is frozen (`FreezeReportAction`). A report is worked out when read.
- The domain's Metrics API is read with explicit times inside the period, a day at a time; only outgoing and forwarded counts, hard bounces, complaints and unsubscribes.

### The MCP server and its tools

- The server loads from the package's own route file, outside `web`, at `mcp/campaigns` by default (never `mcp`; an empty path falls back).
- Signing in is `AuthenticateAgent`, never `auth`: it tries each guard in order, putting `Authorization` back before each (Passport blanks it), answers 401 itself (so laravel/mcp's `WWW-Authenticate` follows) and 403 before listing for a principal with neither right.
- Nothing asks the Gate about the default user (a token is an `ApiToken`). A token shares Laravel's `throttle` bucket with the app's user of the same id.
- Rights: a token's own `read` or `full`; anyone else by the app's `campaigns.full` then `campaigns.read` abilities, which the package never defines.
- A tool is a read tool exactly when it carries `#[IsReadOnly]`; one a principal can't use isn't listed. `RegistryTest` holds every tool's hints, schema and the approval set.
- A refusal is a tool error with `error` in its structured content; every output schema requires nothing at the top level.
- A blank argument is dropped, as if not given, never made null, which an update tool reads as "clear it" (`CampaignsTool::handle()`: laravel/mcp reads the raw body, past `ConvertEmptyStringsToNull`); every `page` takes `PAGE_RULES`, bounded so an offset never overflows. `Principal::current()` is worked out once a request.
- No answer is over 50,000 characters, measured as JSON (a quote two, a control character or U+2028/U+2029 six). Free text goes through `ShownText`; answers are cut where they grow, and size tests run over the `costly characters` dataset. An empty map is cast to an object.
- The approval meta is per tool, so a risky action is its own tool: start, resume, speed-up, test send, archiving a list or template, unblock, an import's run.
- An agent's consent change never carries the request's IP or browser; its meta names the token or person.
- Signed links are signed without the host and built on `campaigns.url`; they answer every refusal as JSON themselves. Downloads are always attachments. The preview frames an agent's HTML in `<iframe sandbox srcdoc>` under a script-free policy, with sample data only; its links' token is derived from the revision and the app key.
- Instructions plus the app's stay under 4,096 characters, set in `boot()`. The app's resources are listed on every request, a broken one reported and left out; a resource page past the limit is refused.
- A start's time carries its offset; up to 15 minutes past starts now, further back is refused.

### Pages

- The pages run outside `web`: no session, no cookie, no CSRF (RFC 8058 one-click needs a plain 200). Opening a link changes nothing; only a POST does.
- One page for unsubscribe and preferences; fixed paths, since delivered mail points at them. A link resolves to the email it came in and shows that email's address, never the person's current one.
- Throttles: GET pages per visitor through the `throttle` alias; POSTs per link (`campaigns.links`).
- The web version renders under a `sandbox` CSP. The pages and footer speak the subscriber's language only when the package's strings exist in it (`PageLocale::pick()`).

## Extension points

- `CampaignDriver` plus the abilities in `Contracts\Drivers`, registered with `Campaigns::extend($name, fn (array $config, Application $app) => ...)` and chosen by `campaigns.driver`: built in, `mailgun`, `smtp` and `fake` (tests: `sent()`, `failNext()`, `webhook()`, `log()`, `suppress()`, `count()`, `failReads()`, `readsWork()`, `keepLogsFor()`; `FakeSendOnlyDriver` and `FakeWebhookDriver` for fewer abilities).
- `ListSource` with the `HasCampaignSubscriber` trait (`campaigns.list_sources`); `Model::withCampaignConsent()`; `Campaigns::recordActivity()`.
- `Audience` (`campaigns.audiences`) and `AgentResource` (`campaigns.resources`).
- `ImportContactsAction` for an app's own imports; `SendConfirmationAction` after a pending double opt-in subscribe.
- The views and strings publish; the actions and `CampaignsServer` resolve through the container.
- `campaigns.mcp.guards`, the `campaigns.read` and `campaigns.full` abilities, `campaigns.mcp.instructions` and `middleware`.
- `Fetching\StaticHostResolver` for apps' tests. The events in `src/Events/`.

## Testing

- `TestCase` runs the stub on in-memory SQLite with foreign keys on and strict models, sets an `app.key`, and lists laravel/mcp's provider in `testbench.yaml` (without it a tool's `Request` holds no arguments).
- `useDatabase($driver)` with the `databases` dataset moves a test onto MySQL or PostgreSQL (`campaigns_testing`; skipped locally without a server, failing on CI). Anything database-specific runs on all three. `CAMPAIGNS_TEST_MYSQL_*` and `CAMPAIGNS_TEST_PGSQL_*` point elsewhere.
- Helpers in `tests/Pest.php`: `sentMessage()`, `sendFromExample()`, `subscriberOn()`, `campaignEmail()`, `sendingCampaign()`, `fakeDriver()`, `sends()`, `outgoing()`, `sendFailure()`, `scriptedSmtp()`, `mailgunEvent()`, `mailgunWebhook()`, `mailgunLogItem()`, `mailgunLogs()`, `testImage()`, `zipOf()`, `resolveHosts()`, `structuredContent()`, `agent($rights, person:)`, `contactsUpload()`, `emailUpload()`, and the `costly characters` dataset.
- `TestCase` sets the `fake` driver. Fake only `SendMessageJob`, so a started campaign still freezes; travel to UTC instants. Run `ProcessFeedbackJob` through `app()->call([$job, 'handle'])`.
- `tests/Fixtures/Mailgun/` holds Mailgun's documented payloads; reads run under `Http::preventStrayRequests()`. The driver's tests swap a `MailgunApiTransport` over `MockHttpClient`; never test a refused transport against a real one. The SMTP driver talks to a real `EsmtpTransport` over `tests/Fixtures/Smtp/ScriptedStream` (Symfony's `SocketStream` is final, so it extends `AbstractStream` over memory streams, keeping Symfony's own reads, writes and debug log), never a server; it answers NOOP, RSET and QUIT unless a step names them.
- `tests/Fixtures/Mcp/FixtureServer` proves the shared tool rules over HTTP; `Server::actingAs()` skips the route's middleware, so sign-in is tested over HTTP only. Tests reading an error's text set `app.debug` off.
- `tests/Fixtures/Member`, `MemberList`, `MembersInCountry`, `RecentContacts` and `MemberDirectory` stand in for an app's accounts, list source, audiences and a resource.
- Compare stored JSON maps with `toEqual()`. Fake the asset disk with an https `url`.
- Laravel skips its CSRF check under unit tests, so the one-click test binds the kernel's CSRF class (`ValidateCsrfToken` on 12, `PreventRequestForgery` on 13) to a subclass that checks anyway; `Route::middlewareGroup()` in a test changes nothing, since the kernel puts its groups back on every request.
- The install tests delete what they publish into Testbench's app, before and after each test.

## Development notes

- `CHANGELOG.md` is generated from GitHub releases by the changelog workflow. Never edit it by hand.
- The PHP floor is 8.2: no typed class constants (`public const string X`) and no `new Foo()->bar()` without wrapping parentheses. PHPStan's `type_coverage.constant` is 0 for that reason.
- Never give a job a method named `queue()` (Laravel's dispatcher calls it), and no job helper named `failed()` or `fail()`.
- Read `campaigns.domain_daily_caps` as a whole map: the dots in its domain keys break dot access.
- The README, `docs/`, `resources/boost/` and `resources/mcp/` describe the same surface: a change to a tool, command, config key or action's behaviour is checked against all four, and `tests/Mcp/GuideToolTest.php` fails when the guidance names a tool the server lacks.
