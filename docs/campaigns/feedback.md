---
title: Feedback and the Stop-Loss
weight: 4
---

What happens to an email after the sending service takes it comes back as events: by webhook within seconds, and, for a driver that reads its service's logs, through the reconciler for anything a webhook missed. Each event is stored once, by the service's event id and the driver, and applied once. Mailgun's events come both ways (its logs with the [reading key](../getting-started/mailgun.md#the-reading-key)). Amazon SES's come only by webhook, since SES keeps no logs the package can read ([Amazon SES](../getting-started/ses.md) lists what each one does), and the [SMTP driver](../getting-started/smtp.md) gets none.

## What each event does

| Event | Effect |
|---|---|
| delivered | the email is delivered; the address's soft bounce count goes back to 0 |
| hard bounce | the address is blocked (`hard_bounce`) |
| soft bounce | counted; three in a row block the address |
| sender blocked | the receiving server refused the sender, nothing against the address |
| suppressed by the service | the service held the address from before and sent nothing: a bounce or complaint blocks it, an unsubscribe opts the person out |
| spam report | the address is blocked (`complaint`) and the person opted out of everything |
| an unsubscribe at the service (Mailgun's) | the person is opted out of everything |
| opened, clicked | the email's first open or click, and the person's engagement; these come from the package's own pixel and links, never the service |

Events arrive late and out of order, so every change moves forward only: a delivery never undoes a bounce, and an email already failed stays failed.

Opens and clicks count only a person's. Gmail's and Yahoo's image proxies load images only when someone opens the email, so they count; Apple's privacy prefetch and link scanners open everything, so they don't. An open within 5 seconds of delivery (of sending, when no delivery was reported) is a machine's, and so is a click within 10 seconds, or within 3 seconds of a click on another link in the same email; anything before the delivery is too. Clicks on the package's own pages (the unsubscribe link) are never engagement. [Opens and Clicks](./tracking.md) has the whole set of rules.

## Refused while sending

Some services refuse an email as it's handed over, as an SMTP server does with a 5xx reply to the recipient or to the email (a refused sender pauses the domain's campaigns instead). The email fails and is never sent again. When the refusal names a mailbox that doesn't exist (the enhanced status codes 5.1.1, 5.1.2, 5.1.3 and 5.1.10 of RFC 3463), it fails as `hard_bounce`: it counts as sent and bounced, the address is blocked, and the stop-loss counts it like any bounce. Any other refusal fails as `rejected` and leaves the address alone. When a campaign's last 10 sends were all refused that way, however slow its pace, it pauses: the content or the sender is what's being refused, and every next email would fail the same way.

## Drivers that read nothing back

A driver with neither webhooks nor logs, such as the [SMTP driver](../getting-started/smtp.md), or one whose settings leave both off (Mailgun without its webhook signing key and its reading key, Amazon SES without its webhook secret), never learns what happens to an email after its service takes it. No delivery, bounce or spam report reaches the package, and neither does an unsubscribe made at the service; the package's own unsubscribe page and the one-click header still work. So its emails end at `sent`, the stop-loss sees only refusals while sending, and the report divides opens and clicks by sent emails. `get_overview` says this, and so do `start_campaign` and `resume_campaign` in their `warnings`.

## The stop-loss

The package pauses a campaign on its own when its numbers say something is wrong, measured per send (an email the service took), from the campaign's own messages:

| Key | Default | Pauses at |
|---|---|---|
| `early_until` | 200 | the sends that count as early on |
| `early_hard_bounces` | 10 | this many hard bounces, early on |
| `early_hard_bounce_rate`, `early_min_sends` | 5.0, 50 | this percent of hard bounces, early on, once this many have gone |
| `hard_bounce_rate`, `hard_bounce_window` | 2.0, 500 | more than this percent of hard bounces among the latest sends, after that |
| `complaints_until`, `complaints` | 2500, 2 | this many spam reports before this many sends |
| `complaint_rate` | 0.08 | more than this percent of spam reports after that |
| `unsubscribe_rate`, `unsubscribe_min_sends` | 1.4, 500 | more than this percent of unsubscribes, once this many have gone |

`campaigns.stop_loss` sets the defaults, and a campaign can set its own `thresholds` before it starts. A limit of 0 means "the first one".

The sending domain is measured too: its last 24 hours across all its campaigns are held to the config's bounce and spam report limits, whatever a campaign sets, and crossing them pauses every sending and scheduled campaign on the domain. Late bounces count, so a campaign that finished can still pause the others.

What paused a campaign counts again from that pause, so a campaign someone resumes after a look isn't paused again by the same numbers. The pause's reason names the numbers and the limit's key, and `CampaignPausedEvent` fires with `automatic` set. A campaign's first spam report, while it keeps sending, fires `CampaignFirstComplaintEvent`. The alert address hears about both.

## Gmail's spam rate

Gmail tells no sender who marked its mail as spam. It reports only a daily rate per domain, in [Google Postmaster Tools](https://postmaster.google.com), and Google asks bulk senders to keep it under 0.1% and never let it reach 0.3%. The package reads it for the sending domain, so an agent sees it in `get_overview`'s `domain.gmail` before it asks to start or speed up a campaign, and again in the `readings` that `start_campaign`, `resume_campaign` and `speed_up_campaign` answer, with the warning in their `warnings`.

To turn it on, an [optional extra](../getting-started/optional-extras.md):

1. [Register the sending domain](https://support.google.com/mail/answer/6227174) in Postmaster Tools and verify it with the DNS record Google gives you. Register the sending domain itself, such as `news.example.com`: the package asks Google about that exact domain.
2. In a Google Cloud project, turn on the Gmail Postmaster Tools API, create a service account with no roles, and download a JSON key for it.
3. In Postmaster Tools, add the service account's address (`...@<project>.iam.gserviceaccount.com`) as a user of the domain.
4. Put the key file in your env, base64-encoded, since a `.env` value can't hold its line breaks (on macOS, `base64 -i service-account.json`):

```bash
base64 -w0 service-account.json
```

```dotenv
CAMPAIGNS_POSTMASTER_CREDENTIALS=eyJ0eXBlIjoic2VydmljZV9hY2NvdW50Ii...
```

The package signs in with that key, asking only for the read-only `postmaster.traffic.readonly` scope, and reads two things: the last 14 days to yesterday (Gmail's numbers run about a day late) and Google's bulk-sender checks. `domain.gmail` shows:

| Field | What it holds |
|---|---|
| `spam_rate`, `spam_rate_date` | the latest day's known spam rate, in percent, and its date |
| `warning` | set when that rate is at or above 0.1%, and worded for Gmail's stop line at 0.3% or above |
| `days` | each of the 14 days with its spam rate and DKIM, SPF and DMARC success, in percent |
| `compliance` | Google's checks (SPF and DKIM, DMARC, encryption, one-click unsubscribe and the rest) for the registrable domain and the subdomain you send from |
| `read`, `note` | whether Google answered, and why not when it didn't |

Gmail reports a day only when the domain sent it enough mail. A day with no report, a day it fills with zeros (DKIM and SPF included) and a day without a spam rate show as `null`: unknown, never clean. A new or quiet domain often has no known day at all. The warning refuses nothing: whether to start, slow down or pause stays with the agent and the person it works for. The guide tells agents to pause a campaign as soon as they can when a start or a resume answers a rate at 0.3% or above (a start once it's sending or scheduled), and never to speed one up at 0.1% or above.

A reading is kept in `campaigns.cache_store` for an hour, so Google is asked at most once an hour; a failed one is tried again after 5 minutes. Each request gives up after 10 seconds. The key, the signed sign-in request and the access token never reach a log, an exception or an answer.

## The reconciler

Every 15 minutes, whether or not sending is switched on:

- emails whose job was lost go back to waiting;
- events stored but never applied are queued again;
- the logs of each driver that reads them (Mailgun's) are replayed per sending domain from half an hour behind (logs fill in out of order), storing every event no webhook brought except `accepted`, which the others prove;
- a send that ended unclear is looked up by its id. If the logs show it, it's marked sent; if they hold nothing an hour after it went, it fails as `not_accepted` and is never resent. Past what the logs reach, it stays `unknown`.

It reads the logs through the driver that sent each email, so a switch of drivers keeps settling the old one's. A driver without logs, or whose settings leave them off, is skipped without a problem reported: its unclear sends stay `unknown`, never resent, and an email its job left `sending` (a worker killed mid-send) becomes `unknown` after 15 minutes, so the campaign can complete. Mailgun's driver needs the [reading key](../getting-started/mailgun.md#the-reading-key).

## Reports

`get_campaign_report` works a campaign's report out when it's read: sends, deliveries, failures by kind, hard bounces, spam reports and unsubscribes (by how they came: a mail app's one-click, the page or the sending service), opens and clicks by people and by machines, the rates, the clicks on the links the most people clicked (`links_total` counts every link clicked), the same by list, language, engagement tier and mailbox provider, and a timeline by day. Rates of bounces, spam reports and unsubscribes are per send, as the stop-loss measures them; opens and clicks are per delivered email, or per sent email when the campaign went through a driver that reads nothing back (`rates.basis` says which).

Each link has three counts. `people` is the number of emails with a person's click on it, and the links are ranked by it, so compare links by `people`. `human` and `machine` count every click, repeat clicks included. `human` also counts a mail app fetching a clicked link a second time: Gmail can do this from Google's network a couple of seconds after the click, with a browser's user agent, and then one Gmail reader's click shows as two `human` clicks and one person. The same goes for the campaign's `clicks`: its `people` are the emails clicked, its `human` and `machine` every click.

Pruning deletes events older than `campaigns.event_retention_days` (180). A finished campaign's report is kept on the campaign first, so it survives its events; a campaign still sending or paused keeps its events until it's done.
