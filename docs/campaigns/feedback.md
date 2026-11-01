---
title: Feedback and the Stop-Loss
weight: 4
---

What happens to an email after Mailgun takes it comes back as events: by webhook within seconds, and from Mailgun's logs through the reconciler for anything a webhook missed. Each event is stored once, by Mailgun's event id, and applied once. Amazon SES's events come only by webhook, since SES keeps no logs the package can read ([Amazon SES](../getting-started/ses.md) lists what each one does).

## What each event does

| Event | Effect |
|---|---|
| delivered | the email is delivered; the address's soft bounce count goes back to 0 |
| hard bounce | the address is blocked (`hard_bounce`) |
| soft bounce | counted; three in a row block the address |
| sender blocked | the receiving server refused the sender, nothing against the address |
| suppressed by Mailgun | Mailgun held the address from before and sent nothing: a bounce or complaint blocks it, an unsubscribe opts the person out |
| spam report | the address is blocked (`complaint`) and the person opted out of everything |
| Mailgun's own unsubscribe | the person is opted out of everything |
| opened, clicked | the email's first open or click, and the person's engagement; these come from the package's own pixel and links, never the service |

Events arrive late and out of order, so every change moves forward only: a delivery never undoes a bounce, and an email already failed stays failed.

Opens and clicks count only a person's. Gmail's and Yahoo's image proxies load images only when someone opens the email, so they count; Apple's privacy prefetch and link scanners open everything, so they don't. An open within 5 seconds of delivery (of sending, when no delivery was reported) is a machine's, and so is a click within 10 seconds, or within 3 seconds of a click on another link in the same email; anything before the delivery is too. Clicks on the package's own pages (the unsubscribe link) are never engagement. [Opens and Clicks](./tracking.md) has the whole set of rules.

## Refused while sending

Some services refuse an email as it's handed over, as an SMTP server does with a 5xx reply to the recipient or to the email (a refused sender pauses the domain's campaigns instead). The email fails and is never sent again. When the refusal names a mailbox that doesn't exist (the enhanced status codes 5.1.1, 5.1.2, 5.1.3 and 5.1.10 of RFC 3463), it fails as `hard_bounce`: it counts as sent and bounced, the address is blocked, and the stop-loss counts it like any bounce. Any other refusal fails as `rejected` and leaves the address alone. When a campaign's last 10 sends were all refused that way, however slow its pace, it pauses: the content or the sender is what's being refused, and every next email would fail the same way.

## Drivers that read nothing back

A driver with neither webhooks nor logs, such as the [SMTP driver](../getting-started/smtp.md), never learns what happens to an email after its service takes it. No delivery, bounce or spam report reaches the package, and neither does an unsubscribe made at the service; the package's own unsubscribe page and the one-click header still work. So its emails end at `sent`, the stop-loss sees only refusals while sending, and the report divides opens and clicks by sent emails. `get_overview` says this, and so do `start_campaign` and `resume_campaign` in their `warning`.

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

## The reconciler

Every 15 minutes, whether or not sending is switched on:

- emails whose job was lost go back to waiting;
- events stored but never applied are queued again;
- Mailgun's logs are replayed per sending domain from half an hour behind (its logs fill in out of order), storing every event no webhook brought except `accepted`, which the others prove;
- a send that ended unclear is looked up by its id. If the logs show it, it's marked sent; if they hold nothing an hour after it went, it fails as `not_accepted` and is never resent. Past what the logs reach, it stays `unknown`.

It reads the logs through the driver that sent each email, so a switch of drivers keeps settling the old one's. A driver without logs is skipped: its unclear sends stay `unknown`, never resent, and an email its job left `sending` (a worker killed mid-send) becomes `unknown` after 15 minutes, so the campaign can complete. Mailgun's driver needs the [reading key](../getting-started/mailgun.md#the-reading-key).

## Reports

`get_campaign_report` works a campaign's report out when it's read: sends, deliveries, failures by kind, hard bounces, spam reports and unsubscribes (by how they came: a mail app's one-click, the page or Mailgun), opens and clicks by people and by machines, the rates, the most clicked links, the same by list, language, engagement tier and mailbox provider, and a timeline by day. Rates of bounces, spam reports and unsubscribes are per send, as the stop-loss measures them; opens and clicks are per delivered email, or per sent email when the campaign went through a driver that reads nothing back (`rates.basis` says which).

Pruning deletes events older than `campaigns.event_retention_days` (180). A finished campaign's report is kept on the campaign first, so it survives its events; a campaign still sending or paused keeps its events until it's done.
