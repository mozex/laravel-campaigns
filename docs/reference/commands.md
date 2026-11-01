---
title: Commands
weight: 2
---

Every command that changes data you'd have to repair by hand is a dry run unless you pass `--force`, and prints what it would do first.

| Command | What it does |
|---|---|
| `campaigns:install` | publishes the config file and the migration |
| `campaigns:token create <name> [--read-only]` | makes a token for the MCP server and prints it once |
| `campaigns:token list` | the tokens, their rights and when each was last used |
| `campaigns:token revoke <name>` | deletes a token |
| `campaigns:sync-list <list> [--id=] [--force]` | brings a dynamic list in step with the records behind it; `--id` syncs one record |
| `campaigns:import-provider-suppressions <domain> [--email=] [--force]` | turns the active driver's bounce and complaint lists (Mailgun's for the domain, or Amazon SES's one list for the account) into blocks, and its unsubscribe list into opt-outs for the marketing domain; `--email` looks up one address. It refuses a driver that reads no lists |
| `campaigns:pump` | starts scheduled campaigns and hands each sending campaign's next emails to the queue |
| `campaigns:reconcile` | puts back emails whose job was lost, replays the logs of each driver that reads them (Mailgun's) for events a webhook missed, and settles sends that ended unclear |
| `campaigns:prune` | deletes provider events older than `campaigns.event_retention_days` once their campaign's report is kept, spent webhook tokens and expired uploads |

The package schedules the last three itself: the pump every minute, the reconciler every 15 minutes and pruning daily, each with an overlap lock in `campaigns.cache_store`. Set `campaigns.schedule` to `false` to schedule them yourself.

The reconciler runs whether or not sending is switched on, since events keep coming after it's switched off. It exits non-zero when a step failed, such as Mailgun's logs being unreadable without the reading key.

Run a sync or an import the careful way: a dry run, then `--id` or `--email` on one record, then the hardest case you have, then everything, reading the same numbers before and after.
