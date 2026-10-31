---
title: Introduction
weight: 0
---

Laravel Campaigns runs your app's email marketing inside the app itself, and hands the work to AI agents. There are no screens. An agent connected to your app over MCP (Claude Code, Codex, claude.ai or ChatGPT) writes an email, uploads it, previews it, sends a test, counts the audience, asks you to approve the exact number of recipients, starts the campaign and watches it. Every one of those steps is a package action, so a screen you build later calls the same code.

## Why inside the app

An email service keeps a copy of your users and drifts from it. Here the lists are your users: a dynamic list follows one of your models, so a new account joins, a changed address moves, a banned account leaves and a deleted one is forgotten, as each happens. Your own data stays usable too. An agent can pick "everyone who bought this course" through a named audience you write, or read your product catalogue to write about it, without database access.

## What it does

- **Consent you can prove.** Every subscribe, opt-out, block and confirmation is an append-only record with its source, its legal basis and, when the person acted, their IP address and browser. One unsubscribe stops all of your marketing to that person, and nothing but the person can undo it.
- **Paced sending on your own queue.** A campaign starts at 100 emails on day one and grows 20% a day, handed to your queue a few a minute, across mailbox providers in turn, engaged readers first. Every number is a setting per campaign.
- **The service's feedback, acted on.** Hard bounces, spam reports and unsubscribes stop an address at once; three soft bounces in a row block it. With Mailgun, a reconciler fills in from its logs whatever a webhook missed.
- **A stop-loss.** A campaign whose bounces, spam reports or unsubscribes pass their limits pauses itself, and so does every campaign on a domain whose last 24 hours look bad. You get an email.
- **The pages emails need.** One-click unsubscribe for mail apps (RFC 8058), an unsubscribe page that lets people subscribe again, double opt-in confirmation, and a web version of every email.
- **Guardrails for agents.** Read-only and full tokens, approval prompts on the risky tools, answers that never show an IP address, links that change nothing when a scanner opens them, and sending switched off until you turn it on.

## What you need

- A Laravel 12 or 13 app on PHP 8.2 or later, with MySQL, MariaDB, PostgreSQL or SQLite.
- A Mailgun account with a sending domain, or an SMTP server ([SMTP](./getting-started/smtp.md)), which reads back only what it refuses on the spot. Another service plugs in as a driver of your own ([Drivers](./reference/drivers.md)), and a driver only has to send.
- A queue worker and Laravel's scheduler running.

[Installation](./getting-started/installation.md) takes it from there.
