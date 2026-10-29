---
title: The MCP Server
weight: 1
---

The server has 48 tools. Each one calls a package action and holds no logic of its own, so a screen you build later can call the same actions and get the same answers and refusals. [Connecting Agents](../getting-started/connecting-agents.md) covers signing in.

## Rights

A tool is read-only (R below) or a write (W). A read-only token, or a person allowed only `campaigns.read`, sees just the read-only tools; a write they can't use isn't listed, and calling it answers "not found". A full token or a person allowed `campaigns.full` sees all of them.

Tools marked "asks" carry the `anthropic/requiresUserInteraction` flag, so Claude Code asks the person before every call, even when it otherwise runs without prompts. Other clients ignore the flag, and the server's guidance tells every agent to get the person's approval for them anyway.

## The tools

**Start here**

| Tool | | What it does |
|---|---|---|
| `get_overview` | R | how sending is set up and what's switched off, the sending domain's standing, every list with its counts, the campaigns running, and the placeholders an email may use |
| `get_guide` | R | the full workflow agents follow, and your app's own resources a page at a time |

**Lists and people**

| Tool | | What it does |
|---|---|---|
| `list_lists` | R | the lists with their counts (archived ones when asked) |
| `create_list`, `update_list` | W | make or rename a static list |
| `archive_list` | W, asks | hide a static list and close it to additions, or restore it |
| `add_contacts`, `remove_contacts` | W | up to 100 people on or off a static list |
| `set_contact_fields` | W | a person's custom fields |
| `list_subscribers` | R | people a page at a time, with filters |
| `get_subscriber` | R | one person, their lists and their latest consent records |
| `unsubscribe_contact`, `block_contact` | W | opt a person out of everything, or block an address |
| `unblock_contact` | W, asks | clear a block, and say whether Mailgun still holds the address |
| `preview_contacts_import` | W | what a CSV import would do, without doing it |
| `run_contacts_import` | W, asks | run that import |

**Files, templates and checks**

| Tool | | What it does |
|---|---|---|
| `create_upload_link` | W | a one-time link and the curl command that sends a file to it |
| `list_assets`, `add_image` | R, W | the image library, and an image added from an upload, inline or from an https address |
| `list_templates`, `get_template` | R | the starter templates and your app's, and one with links to download its email |
| `create_template`, `update_template` | W | a template from an upload, inline content, another template or a starter; its name and description |
| `archive_template` | W, asks | hide a template from the list |
| `add_revision`, `list_revisions`, `restore_revision` | W, R, W | save, list and bring back versions of a template's or a draft campaign's email |
| `run_checks`, `get_check_results` | W, R | queue the pre-send checks and read them |
| `get_preview_link` | R | a link, good for a week, to the email at desktop and phone width with made-up details |

**Drafting campaigns**

| Tool | | What it does |
|---|---|---|
| `list_campaigns`, `get_campaign` | R | campaigns by status, and one with its settings and current email |
| `create_campaign`, `update_campaign`, `duplicate_campaign` | W | a draft from a template, an upload or inline content; its settings; a copy |
| `list_audiences`, `save_audience` | R, W | your named audiences and saved ones; save a set of filters under a name |
| `preview_audience` | R | how many a campaign would reach, who was dropped and why, and the breakdowns |
| `send_test` | W, asks | the email to the app's test addresses |
| `get_test_sends` | R | whether each test went |

**Sending and watching**

| Tool | | What it does |
|---|---|---|
| `start_campaign` | W, asks | start now or at a time, with the recipient count the person approved |
| `pause_campaign`, `cancel_campaign` | W | stop at once, or for good |
| `resume_campaign` | W, asks | carry on after a pause |
| `slow_down_campaign` | W | any pace change that sends no faster |
| `speed_up_campaign` | W, asks | any pace change |
| `get_campaign_status` | R | where sending stands: messages by status, failures by kind, the queue, the pace and limits in effect |
| `get_campaign_report` | R | sends, deliveries, bounces, spam reports, unsubscribes, opens and clicks, with breakdowns and a timeline |

## How agents work with it

The server's instructions tell an agent to call `get_overview` first and read the guide before writing or sending anything. The guide (the `campaigns://guide` resource, or `get_guide` for clients that read no resources) walks through the whole loop:

1. Write the email in a working folder, or start from a template.
2. Upload it and save it as a template or a campaign's email.
3. Open the preview link after every new version.
4. Send a test to the app's test addresses and check it went.
5. Set the campaign's lists, audience, sender and pace, and count who it reaches.
6. Run the pre-send checks and read the results.
7. Read the sending domain's standing.
8. Ask the person to approve the exact recipient count and the pace, with the checks and the domain's numbers in hand.
9. Start or schedule it with that count, and poll its status until it's sending or scheduled.
10. Watch it, and pause it the moment something looks wrong.

A start freezes the audience and the email in a queued job and answers `starting` at once. When the count it finds differs from the one approved, the campaign goes back to a draft with `start_error` saying why, and the agent previews and asks again.

## Answers

Every answer is structured JSON with an output schema, and none is longer than 50,000 characters, the point past which Claude Code saves an answer to a file instead of reading it. Lists come a page at a time, long text is cut, and a stored audience shows counts, never the addresses in it. Answers never show a person's IP address or browser, which stay on the server for a complaint.

A refusal is a tool error with the reason in `error`, such as a campaign that can't start while sending is switched off. The guide tells agents to report the reason rather than retry.
