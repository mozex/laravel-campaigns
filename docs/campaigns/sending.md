---
title: Sending and Pace
weight: 3
---

A campaign never goes out in one burst. A pump hands its emails to your queue a few at a time, at a pace that starts small and grows each day, so mailbox providers get to know your sending domain before the volume arrives.

## Starting

`start_campaign` takes the recipient count the person approved. It claims the draft and queues a job that freezes the campaign in one transaction: the email (with its UTM tags) and one message row per recipient, in send order. If the count it finds differs from the one approved (someone unsubscribed in between), it rolls back and returns the campaign to a draft with `start_error` saying why, so nothing goes out on a number nobody agreed to.

A campaign given a time (`at`, with its UTC offset, at most a year ahead) is frozen at once and waits as `scheduled`; the pump starts it when its time comes. A time up to 15 minutes in the past starts it now; one further back is refused, since a mistyped year would otherwise send at once.

People who unsubscribe, are blocked or reach the sunset after the freeze are still skipped when their turn comes: every send checks the person again.

## The send order

The freeze sorts recipients by engagement tier (engaged, active, then silent), and within a tier takes mailbox providers in turn (Gmail, Microsoft, Yahoo, Apple and the rest), so the people most likely to read come first and no single provider gets a run of emails.

## The pace

| Setting | Default | Meaning |
|---|---|---|
| `first_day` | 100 | emails on the first day |
| `daily_growth` | 20 | percent more each day than the day before |
| `daily_cap` | none | the most in a day, whatever the growth |
| `per_minute` | 10 | the most the pump hands to the queue in a minute |
| `sending_hours` | none | a daily window, such as `['09:00', '18:00']` |
| `timezone` | `UTC` | the window's timezone |

A campaign stores only what it was given; the rest follows `campaigns.pace` when read. Day n is the nth 24 hours from the campaign's start, or from a resume after a pause of more than a day.

Every minute, the pump works out what each sending campaign may hand out now, the least of:

- `per_minute` less what it handed out this calendar minute (halved for ten minutes after Mailgun took nothing, such as a rate limit);
- the day's volume less what it sent that day;
- its sending domain's daily cap less the domain's last 24 hours, across every campaign on it and the double opt-in emails.

Outside its sending hours, it may hand out nothing. A domain's cap is its entry in `campaigns.domain_daily_caps`, else the smallest `daily_cap` among its sending campaigns, else none.

## While it runs

- **Pausing** stops handing out emails at once; queued ones wait. It works whatever else is going on, sending switched off included.
- **Resuming** carries on where it stopped. A campaign paused before its scheduled time waits for that time again.
- **Slowing down** takes any change that sends no faster. **Speeding up** takes any change. Both change only the values given.
- **Cancelling** stops a campaign for good. Its unsent emails stay as they were, so its numbers show how far it got.

Starting, resuming, speeding up and test emails are refused while sending is switched off; pausing, slowing down and cancelling never are, since stopping must never wait.

A campaign completes once nothing is left to send. Its sent emails can still change afterwards: late bounces, opens and the reconciler keep its report moving.

## The queue

Each email is one job on the `campaigns` queue. The job takes its email only if its claim is fresh (15 minutes) and the campaign is still sending, then checks the person again and sends. When Mailgun's answer is unclear (a timeout after the request went out, a 504), the email is marked unknown and never sent again; the reconciler finds out from Mailgun's logs whether it went. Each unclear send halves the campaign's rate for ten minutes, and ten within five minutes pause it, so a lost connection never spends the audience on emails that may not have gone.

If the campaign's queued emails wait more than five minutes, the queue has stalled (no worker is taking them): the campaign hands out nothing more until they go, `CampaignQueueStalledEvent` fires each minute, and the alert address hears about it.

If Mailgun refuses the domain itself (a revoked key, a disabled domain), the email goes back to waiting and every campaign on the domain pauses until someone resumes it.

A service can also refuse one email for good as it's sent, as an SMTP server does with a 5xx reply. That email fails and is never sent again: as a hard bounce that blocks the address when the refusal names a mailbox that doesn't exist, else as `rejected`, and 10 `rejected` sends in a row pause the campaign ([Feedback](./feedback.md#refused-while-sending)).
