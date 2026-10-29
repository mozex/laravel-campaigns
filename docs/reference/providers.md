---
title: Custom Providers
weight: 5
---

`campaigns.provider` takes `mailgun`, `fake`, or the class name of your own `Mozex\Campaigns\Contracts\CampaignProvider`, bound as a singleton. A provider does six jobs:

| Method | What it does |
|---|---|
| `send(OutgoingMessage $message): SendReceipt` | sends one email and returns the provider's message id |
| `verifyWebhook(Request $request): bool`, `parseWebhook(Request $request): array` | checks a webhook's signature and reads its events as `Feedback` |
| `eventsForMessage()`, `eventsBetween()`, `logDays()` | reads the provider's logs, by message or by domain and period, and how far back they reach |
| `suppressions()`, `suppressionsFor()` | reads the provider's bounce, complaint and unsubscribe lists |
| `domainMetrics(string $domain, CarbonInterface $from, CarbonInterface $to): DomainMetrics` | the domain's counts a day at a time, for the overview |
| `tracks(): bool` | whether it tracks opens and clicks itself |

## Sending

`send()` either returns or throws `Mozex\Campaigns\Sending\SendFailed` with a `SendFailure` that tells the package what happened, so an email is never sent twice:

- `NotAccepted`: the provider took nothing and may take it later (a rate limit, an outage). The email goes back to waiting and the campaign's rate halves for ten minutes.
- `DomainRefused`: nothing more can go from this domain until someone acts (a revoked key, a disabled domain). Every campaign on the domain pauses.
- `Unclear`: anything you can't be sure of, such as a timeout after the request went out. The email is marked unknown, never resent, and the reconciler looks it up in the logs.

Decide by what the transport tells you, never by the words in the provider's answer, and keep addresses out of the exception's message: it reaches logs and error trackers.

## Reading

Every read method may throw `Mozex\Campaigns\CampaignsException` when the provider can't be read; the reconciler reports it and carries on. `eventsForMessage()` returns `null` when it can't tell (more pages than it read, say), never an empty list, which would mark the email failed.

`domainMetrics()` returns a `Mozex\Campaigns\Sending\DomainMetrics` whose `days` are keyed by UTC date (`Y-m-d`) within the period, each with `sent`, `forwarded`, `hard_bounces`, `complaints` and `unsubscribes`. A provider that has no such numbers can return zeros; the overview's own last 24 hours come from the package's records either way.
