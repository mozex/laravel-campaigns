# Changelog

All notable changes to `laravel-campaigns` will be documented in this file.

## 0.2.2 - 2026-11-24

### What's Changed

* Once an email's pre-send checks had run, Claude Code refused the answers of `get_template`, `get_check_results`, `list_revisions` and every tool that shows that revision, with "passed must be integer,null". The checks stored `passed` as `true` or `false` while each tool declares it a number. `passed` is now how many checks passed and `failed` how many failed (the rest were skipped), and the preview page's line reads "6 passed, 1 failed." instead of "1 passed".
* Results stored by an earlier version are read the same way: where `passed` was stored as `true` or `false`, the answer counts the checks that passed. Running the checks again isn't needed.
* A resource in `campaigns.resources` whose name is only digits, such as `'7'`, stopped the campaigns server from starting, so every MCP request failed. Its name is now read as text, and `get_guide` lists it as `"7"`.

Update with `composer update mozex/laravel-campaigns`; there's nothing to publish or migrate.

**Full Changelog**: https://github.com/mozex/laravel-campaigns/compare/0.2.1...0.2.2

## 0.2.1 - 2026-11-23

### What's Changed

* The `unsubscribe_url` placeholder's description, which agents read in the overview and the guide, said the page unsubscribes the recipient from all of the app's email. It now says what the page does: it stops every campaign the app sends. An app's own transactional mail isn't touched. The placeholder table in the emails docs and the unsubscribe page's description in the subscriptions docs say the same.
* The agents' guide said the pre-send checks and the UTM tags never see the second link in the starter template's Outlook-only button block (`<!--[if mso]>`). They do, and so does the click tracking. The guide now says only Outlook for Windows draws that block, so both links still need changing.
* The `cache_store` setting's comment and its row in the configuration docs name the provider's usage reading among what the store keeps.

Update with `composer update mozex/laravel-campaigns`; there's nothing to publish or migrate.

**Full Changelog**: https://github.com/mozex/laravel-campaigns/compare/0.2.0...0.2.1

## 0.2.0 - 2026-11-23

### What's Changed

* Who may use the MCP server is now one method on your user model. Implement `Mozex\Campaigns\Contracts\HasCampaignRights` and return `Rights::Full`, `Rights::Read` or `null` from `campaignRights()`. The package asks Laravel's gate nothing, so a `Gate::before()` hook or a permission package's super-admin role grants nothing unless your method calls `can()`.
* Lists can be default lists. A campaign created without lists goes to the lists marked `is_default`, and a list that isn't marked gets only the campaigns that name it. Mark one with `create_list` or `update_list` (a dynamic list included), or with `UpdateListAction` in your code. Every list in a tool's answer carries `is_default`, and archiving a list clears its mark.
* `list_lists` shows how each list fills and when it was used: `records` (the type of record a dynamic list follows), `recent_joins` (the last 30 days' joins by consent source, the ten largest and `other`), `last_joined_at` and `last_campaign`.
* `get_overview` shows the sending domain's Gmail spam rate, DKIM, SPF and DMARC success for the last 14 days, and Google's bulk-sender checks, read from Google Postmaster Tools with a service account key in `CAMPAIGNS_POSTMASTER_CREDENTIALS`. A day Gmail didn't report shows as unknown, never clean.
* `get_overview` shows what the provider account has sent against its plan or quota: Mailgun's processed emails this calendar month, both regions added up, against `CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED`, or Amazon SES's 24-hour sending quota.
* `start_campaign`, `resume_campaign` and `speed_up_campaign` read Gmail's numbers and the provider's usage again once the change is made, and answer them as `readings` beside `warnings`, a list in words. A warning comes for Gmail's rate at 0.1% or above, for emails still to send (this campaign's and the app's other open ones') that would pass the plan this month, for a pace that would pass what the quota leaves in the next 24 hours, and for a driver that reads nothing back. An agent in Claude Code, Claude's desktop app, claude.ai or ChatGPT gets the same numbers. Warnings refuse nothing.
* Driver authors can implement the new optional `ReadsUsage` ability; the fake driver takes `used()` in tests.
* A subscriber's `blocked_reason` in a tool's output schema now allows `null`, which a client that checks answers against their schema refused for every subscriber who isn't blocked.

### Upgrading

Change your constraint to `^0.2.0` in `composer.json` (`^0.1.0` stops below 0.2), then `composer update mozex/laravel-campaigns`.

Run `php artisan campaigns:install` again, then `php artisan migrate`. It publishes only the new `upgrade_campaigns_tables_to_0_2` migration beside the create migration you already have; it adds `is_default` to `campaign_lists` and an index on `campaign_consent_records`. If your migrations live outside `database/migrations`, copy `database/migrations/upgrade_campaigns_tables_to_0_2.php.stub` from the package into your folder yourself, as a `.php` file with a timestamp after your create migration's. If `schema:dump --prune` removed the create migration, delete the second create migration the install command publishes before you migrate.

The `campaigns.full` and `campaigns.read` gate abilities are no longer read. Move their checks, a ban among them, into `campaignRights()` on your user model. A user model without the contract is refused with a 403, whose message changed.

The start, resume and speed-up answers no longer have `warning`: read `warnings`, a list of strings. `list_lists` answers 10 lists a page, where it answered 25. A `ConsentContext`'s source is now shown to agents in `list_lists`, so keep sources to a few stable names.

The package now requires PHP's `openssl` extension. To show Gmail's numbers, set `CAMPAIGNS_POSTMASTER_CREDENTIALS` to a Google service account's JSON key, base64-encoded, and add the account's address as a user of the sending domain in Postmaster Tools; the feedback docs have the steps. Have your error tracker censor the `Authorization` header on outgoing requests, the token request's `assertion` field and its response's `access_token`.

With Mailgun, set `CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED` to the emails your plan includes each month (50000 on Foundation) to be warned before a campaign takes the account past it; the existing `CAMPAIGNS_MAILGUN_API_KEY` reads the count. With Amazon SES, give the campaigns mailer's credentials `ses:GetAccount`.

**Full Changelog**: https://github.com/mozex/laravel-campaigns/compare/0.1.1...0.2.0

## 0.1.1 - 2026-11-23

### What's Changed

* A record that couldn't join its dynamic list when it was created, such as an account whose address wasn't confirmed yet, now joins under the consent context around the save that makes it eligible. Wrap that save (`markEmailAsVerified()`, or the verification route's request through a middleware) in `withCampaignConsent()` and its consent record keeps the source, basis, notice version, IP address and browser you give, instead of being recorded as a plain sync. Only a record that this save makes eligible takes the context, and never its `accepted`, so another account saved inside the same callback keeps its own consent.
* Forgetting a subscriber whose address stays blocked or opted out now unlinks the mail it was sent, as it already did for a deleted subscriber. The kept row holds only the address, its state and the consent records that prove it, and an unsubscribe link in an old email to it changes nothing.
* The guide to connecting agents covers what an OAuth sign-in and a token mean for your gate: the abilities hold your own account checks (a ban), spatie/laravel-permission checks name their guard, and code an MCP call runs never asks the gate about a token.

Update with `composer update mozex/laravel-campaigns`; there's nothing to publish or migrate. A row forgotten under 0.1.0 keeps its mail's link until `ForgetSubscriberAction` runs on it again.

**Full Changelog**: https://github.com/mozex/laravel-campaigns/compare/0.1.0...0.1.1

## 0.1.0 - 2026-11-11

### What's Changed

* An MCP server with 48 tools, so an agent can write, upload, preview and test an email, count an audience, and start, pace, pause and report on a campaign, signed in with a read-only or full token or your app's own sign-in
* Dynamic lists that follow one of your models, static lists for everyone else, and contact imports that run as a dry run first
* An append-only consent ledger: every subscribe, opt-out, block and confirmation is recorded with its source and basis, and one unsubscribe stops all marketing to that person
* Paced sending from your own queue, one message at a time, starting at 100 emails on a campaign's first day and growing each day, engaged readers first
* Drivers for Mailgun, Amazon SES and SMTP, and `Campaigns::extend()` for any other service
* Bounces, spam reports and unsubscribes read back from Mailgun's webhooks and logs or from SES's events through EventBridge, and a stop-loss that pauses a campaign or a whole sending domain past its limits and emails you
* Opens and clicks tracked by your app on every driver, with privacy prefetches and link scanners counted as machines, and a report that counts the people who clicked each link
* One-click unsubscribe, an unsubscribe and preferences page, double opt-in confirmation and a web version, each link's token stored only as a hash

Install it with `composer require mozex/laravel-campaigns`. Until 1.0.0, a fix comes as a `0.1.x` patch, which `composer update mozex/laravel-campaigns` installs. A version that adds a migration or changes how something works is the next minor (`0.2.0`), outside the `^0.1.0` constraint Composer writes: raise it by hand, then publish and run its migrations as its notes say.

Setup starts with the [installation guide](https://github.com/mozex/laravel-campaigns/blob/0.1.0/docs/getting-started/installation.md).

**Full Changelog**: https://github.com/mozex/laravel-campaigns/commits/0.1.0
