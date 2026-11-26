---
title: Optional Extras
weight: 6
---

Everything here is off until you set it up, and skipping any of it breaks nothing: no scheduled command, report or alert fails for want of it. While one is missing, `get_overview` says in `sending.notes` what it would add and where its page is, so an agent can tell you what you're going without.

| Extra | What it adds | Without it |
|---|---|---|
| [Mailgun's reading key](#mailguns-reading-key) | missed events replayed from Mailgun's logs, unclear sends looked up, the suppressions import, the domain's daily counts, the account's usage | events a webhook missed stay missed, an unclear send stays `unknown`, no counts or usage |
| [Your Mailgun plan's volume](#your-mailgun-plans-volume) | a warning when a campaign would take the account past its monthly emails | the usage shows, with no warning |
| [Amazon SES's daily counts](#amazon-sess-daily-counts) | SES's own sends, bounces and complaints by day in the overview | the overview shows the app's own numbers only |
| [Gmail's spam rate](#gmails-spam-rate) | the sending domain's Gmail spam rate and Google's sender checks, before and after every start | Gmail's spam reports stay unseen, since Gmail sends them to nobody |
| [The alert address](#the-alert-address) | an email when a campaign pauses itself, gets its first spam report, or its queue stalls | nobody is told; agents still see each in the campaign's status |
| [The spam score check](#the-spam-score-check) | a SpamAssassin score among the pre-send checks | the other checks still run |
| [OAuth sign-in](#oauth-sign-in-for-chat-apps) | claude.ai, ChatGPT and Claude's desktop app connect as a signed-in person | only clients that send a token's header connect |

## Mailgun's reading key

A domain's sending key can only send. An account API key lets the driver also read Mailgun's logs, suppression lists, daily counts and usage: `CAMPAIGNS_MAILGUN_API_KEY`. [Mailgun](./mailgun.md#the-reading-key) says which role to give it and what each read does.

## Your Mailgun plan's volume

With the reading key in place, `CAMPAIGNS_MAILGUN_MONTHLY_INCLUDED` tells the package how many emails your plan includes each month. Start, resume and speed-up answers then warn when a campaign would pass it ([Mailgun](./mailgun.md#your-plans-monthly-volume)). Mailgun bills what passes it rather than refusing it, so the warning stops nothing.

## Amazon SES's daily counts

SES gives daily counts per domain only through Virtual Deliverability Manager. Turn it on in SES and set `CAMPAIGNS_SES_VDM=true` ([Amazon SES](./ses.md#suppressions-and-daily-counts)). SES's sending quota shows in the overview either way.

## Gmail's spam rate

Gmail tells no sender who marked its mail as spam, so no driver hears about those reports. It publishes only a daily rate per domain in Google Postmaster Tools, and asks bulk senders to keep it below 0.3%. With a Google service account key in `CAMPAIGNS_POSTMASTER_CREDENTIALS`, the server reads that rate for the sending domain and shows it in `get_overview` and in every start, resume and speed-up answer, so an agent in any client sees it before it asks you to send more. [Gmail's spam rate](../campaigns/feedback.md#gmails-spam-rate) has the four setup steps and what the reading shows.

## The alert address

```dotenv
CAMPAIGNS_ALERT_ADDRESS=ops@example.com
```

The package mails it when the stop-loss pauses a campaign or a whole sending domain, when a campaign gets its first spam report, and when a campaign's queue stalls (at most once every six hours), through your app's default mailer rather than the campaigns mailer, since the sending domain may be what just failed. [Events](../reference/events.md#the-alert-mail) has the details, and the events behind it if you'd rather send a message to a chat channel.

## The spam score check

```php
// config/campaigns.php
'checks' => [
    'spam_score' => true,
],
```

The pre-send checks then send each rendered email to Postmark's free SpamAssassin checker and report its score ([Emails and Templates](../campaigns/emails.md#pre-send-checks)). It's off by default because the email leaves your server for a third party, sample details in place of a real person's.

## OAuth sign-in for chat apps

A token suits a client that sends a fixed header, such as Claude Code, Cursor or Codex. Chat apps such as claude.ai and ChatGPT sign a person in with OAuth instead. Add an OAuth server (Laravel's MCP package builds its OAuth routes on Laravel Passport) and list its guard in `campaigns.mcp.guards`; your user model then decides each person's rights. [Connecting Agents](./connecting-agents.md#signing-people-in-with-oauth) walks through it.

## Your own data and rules

Beyond setup, the package takes what your app knows:

- [named audiences and resources](../agents/your-data.md), so agents can pick people by your own records and read your products or brand guide;
- [your own activity](../lists-and-subscribers/dynamic-lists.md#your-own-activity-as-engagement), such as sign-ins and purchases, so a reader who never opens email still counts as engaged;
- [your own instructions](./connecting-agents.md#your-own-instructions) for agents, and a [throttle](./connecting-agents.md#throttling) on the MCP server;
- [listeners](../reference/events.md) on its events, to tell your CRM about an unsubscribe or your chat about a pause.
