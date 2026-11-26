---
title: Introduction
weight: 0
---

Laravel Campaigns runs your email marketing inside your own Laravel app, and AI agents operate it. There are no screens. The app keeps the lists, the consent records, the templates and the campaigns, and its own queue sends them. The agent can be any AI app that speaks MCP (Claude Code, Claude's desktop app, claude.ai, ChatGPT, Cursor, Codex): it connects to the MCP server the package adds to your app, writes an email, uploads it, previews it, sends a test, counts the audience, asks you to approve the exact number of recipients, starts the campaign and watches it. Every one of those steps is a package action, so a screen you build later calls the same code.

## Why inside the app

An email service keeps a copy of your users and drifts from it. Here the lists are your users: a dynamic list follows one of your models, so a new account joins, a changed address moves, a banned account leaves and a deleted one is forgotten, as each happens. Your own data stays usable too. An agent can pick "everyone who bought this course" through a named audience you write, or read your product catalogue to write about it, without database access.

The sending service only delivers. Emails leave through a driver: Mailgun, Amazon SES and any SMTP server are built in, and another service takes a driver of your own, which only has to send. Opens and clicks are counted by your app, the same whichever service sends.

## What it does

- **Consent you can prove.** Every subscribe, opt-out, block and confirmation is an append-only record with its source, its legal basis and, when the person acted, their IP address and browser. One unsubscribe stops all of your marketing to that person, and nothing but the person can undo it.
- **Paced sending on your own queue.** A campaign starts at 100 emails on day one and grows 20% a day, handed to your queue a few a minute, across mailbox providers in turn, engaged readers first. Every number is a setting per campaign.
- **The service's feedback, acted on.** Hard bounces, spam reports and unsubscribes stop an address at once; three soft bounces in a row block it. When the service keeps logs the driver reads (Mailgun's), a reconciler fills in whatever a webhook missed.
- **A stop-loss.** A campaign whose bounces, spam reports or unsubscribes pass their limits pauses itself, and so does every campaign on a domain whose last 24 hours look bad. You get an email.
- **The pages emails need.** One-click unsubscribe for mail apps (RFC 8058), an unsubscribe page that lets people subscribe again, double opt-in confirmation, and a web version of every email.
- **Guardrails for agents.** Read-only and full rights, approval prompts on the risky tools, answers that never show an IP address, links that change nothing when a scanner opens them, and sending switched off until you turn it on.

## What you need

- A Laravel 12 or 13 app on PHP 8.2 or later, with MySQL, MariaDB, PostgreSQL or SQLite, served over https.
- A sending service: a [Mailgun](./getting-started/mailgun.md) or [Amazon SES](./getting-started/ses.md) account, or an [SMTP](./getting-started/smtp.md) server, which reads back only what it refuses on the spot. Another service plugs in as a [driver](./reference/drivers.md) of your own.
- A domain to send from whose DNS you can change.
- A queue worker and Laravel's scheduler running.
- An MCP client for the agent.

[Installation](./getting-started/installation.md) goes through the setup in order: the required steps first, then the [optional extras](./getting-started/optional-extras.md).
