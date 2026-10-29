---
title: Configuration
weight: 1
---

`php artisan campaigns:install` publishes `config/campaigns.php`, where each key carries a comment. A published file that predates a key added in a later version still gets that key's default: the package merges nested groups (`pace`, `stop_loss`, `mcp` and the rest) key by key, while a list you set stands as written.

## Sending

| Key | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `CAMPAIGNS_ENABLED` | `false` | the master switch: nothing is sent while it's off |
| `url` | `CAMPAIGNS_URL` | `APP_URL` | the base of every link in an email |
| `name` | `CAMPAIGNS_NAME` | `APP_NAME` | what the pages and emails call your app |
| `mail.mailer` | `CAMPAIGNS_MAILER` | `campaigns` | the mailer campaigns go through |
| `mail.from.address`, `mail.from.name` | `CAMPAIGNS_FROM_ADDRESS`, `CAMPAIGNS_FROM_NAME` | the mailer's own sender | who campaigns come from |
| `mail.reply_to` | `CAMPAIGNS_REPLY_TO` | none | where replies go |
| `mail.domain` | `CAMPAIGNS_DOMAIN` | the sender's domain | the marketing domain a From address must be on |
| `provider` | `CAMPAIGNS_PROVIDER` | `mailgun` | `mailgun`, `fake` or your own class |
| `mailgun.webhook_signing_key` | `MAILGUN_WEBHOOK_SIGNING_KEY` | none | verifies Mailgun's webhooks |
| `mailgun.api_key` | `CAMPAIGNS_MAILGUN_API_KEY` | none | reads Mailgun's logs, metrics and suppression lists |
| `mailgun.endpoint` | `CAMPAIGNS_MAILGUN_ENDPOINT` | the mailer's endpoint, else `api.mailgun.net` | the region's API host |
| `mailgun.log_days` | | `5` | how many days back Mailgun keeps logs on your plan |
| `no_open_tracking_countries` | | `['FR']` | where opens are never tracked |
| `postal_address` | `CAMPAIGNS_POSTAL_ADDRESS` | none | shown in the footer |
| `privacy_url` | `CAMPAIGNS_PRIVACY_URL` | none | linked in the footer |
| `alert_address` | `CAMPAIGNS_ALERT_ADDRESS` | none | gets the alert mail |
| `test_addresses` | `CAMPAIGNS_TEST_ADDRESSES` | none | the only addresses test emails may go to, comma-separated |
| `locale` | `CAMPAIGNS_LOCALE` | `app.locale` | the language every email must have |
| `utm.source`, `utm.medium`, `utm.hosts` | | `newsletter`, `email`, your app's hosts | the UTM tags added to links to your own sites when a campaign starts |

## Files and checks

| Key | Env | Default | What it does |
|---|---|---|---|
| `assets.disk`, `assets.path` | `CAMPAIGNS_ASSETS_DISK` | `public`, `campaigns/assets` | where email images are stored: a public disk with https addresses that never change |
| `assets.max_size` | | 5 MB | the largest image |
| `uploads.disk`, `uploads.path` | `CAMPAIGNS_UPLOADS_DISK` | `local`, `campaigns/uploads` | a private disk where uploads wait for the tool that uses them |
| `uploads.max_size` | | 25 MB | the largest upload, held to PHP's `post_max_size` too |
| `checks.spam_score` | | `false` | sends the rendered email to Postmark's free SpamAssassin checker as one of the pre-send checks |

## Lists, audiences and agents

| Key | Default | What it does |
|---|---|---|
| `list_sources` | `[]` | your dynamic lists, `key => ListSource class` |
| `audiences` | `[]` | your named audiences, `name => Audience class` |
| `resources` | `[]` | data agents may read, `name => AgentResource class` |
| `mcp.enabled` (`CAMPAIGNS_MCP_ENABLED`) | `true` | the MCP server |
| `mcp.path` (`CAMPAIGNS_MCP_PATH`) | `mcp/campaigns` | where it answers |
| `mcp.guards` | `['campaigns']` | the guards it signs callers in with, in order (a list or a comma-separated string) |
| `mcp.middleware` | `[]` | middleware after sign-in, such as a throttle |
| `mcp.instructions` | `null` | your own instructions for agents, added after the package's |
| `engagement.engaged_days`, `active_days` | `90`, `365` | how recently someone must have opened, clicked or been active to count as engaged or active |
| `engagement.sunset_months` | `12` | how long an address may stay silent before it's no longer mailed; `null` never stops one |

## Running

| Key | Env | Default | What it does |
|---|---|---|---|
| `queue.connection`, `queue.name` | `CAMPAIGNS_QUEUE_CONNECTION`, `CAMPAIGNS_QUEUE` | the default connection, `campaigns` | where the jobs run |
| `cache_store` | `CAMPAIGNS_CACHE_STORE` | the default store | the scheduled commands' locks and a few marks; use a store your deploys don't flush |
| `schedule` | | `true` | whether the package schedules its commands |
| `event_retention_days` | | `180` | how long provider events are kept |

## Pace and limits

`pace` is what a campaign falls back to for any value it leaves unset: `first_day` (100 emails on day one), `daily_growth` (20% more each day), `daily_cap` (none), `per_minute` (10), `sending_hours` (none, or a window such as `['09:00', '18:00']`) and `timezone` (`UTC`). `domain_daily_caps` caps a sending domain across all its campaigns, `['news.example.com' => 5000]`. [Sending and Pace](../campaigns/sending.md) explains how they combine.

`stop_loss` sets when a campaign pauses itself. [Feedback and the Stop-Loss](../campaigns/feedback.md) lists each key and its default.
