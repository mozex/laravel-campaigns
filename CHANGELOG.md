# Changelog

All notable changes to `laravel-campaigns` will be documented in this file.

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

Versions before 1.0.0 aren't on Packagist. To install one, add `https://github.com/mozex/laravel-campaigns` to your app's `composer.json` as a `vcs` repository, give Composer a GitHub token that can read it, and require `mozex/laravel-campaigns` at `^0.1`. A `0.1.x` fix arrives with `composer update mozex/laravel-campaigns`. A version that adds a migration or changes how something works is the next minor (`0.2.0`), which `^0.1` won't install: raise the constraint by hand, then publish and run its migrations as its notes say.

Setup starts with the [installation guide](https://github.com/mozex/laravel-campaigns/blob/0.1.0/docs/getting-started/installation.md).

**Full Changelog**: https://github.com/mozex/laravel-campaigns/commits/0.1.0
