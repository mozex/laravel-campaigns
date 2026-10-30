# Running this app's email campaigns

This server runs the app's email marketing on the app's own server. Its lists are views of the app's users (dynamic lists) or contacts added by hand (static lists); its templates and campaign emails live here as numbered revisions; and its queue sends each campaign one email at a time, at the pace the campaign sets, through the sending provider. Whatever reaches this server is live: the people on its lists are real.

## Start here

Call `get_overview` first. It tells you:

- whether sending is switched on, which provider, mailer and domain send, who emails come from, and what is switched off or missing (`sending.notes`, in words);
- the addresses a test email may go to;
- the sending domain's standing (`domain`): its last 24 hours from the app's own records (sends, hard bounces, spam reports and unsubscribes, with their rates per send), its daily cap and what is left of it, and the provider's own counts for its last 30 days;
- every list in use with its counts: `subscribed`, `pending` (waiting for a double opt-in), `unsubscribed`, `mailable` (subscribed people who aren't opted out, blocked or sunset) and the subscribed people by engagement tier;
- the campaigns that are starting, scheduled, sending or paused;
- the placeholders an email may hold, with this app's values.

`get_guide` gives this guide and names the app's own resources (a brand guide, its products and the like), which it reads a page at a time when you name one. They are MCP resources too (`campaigns://resources/<name>`).

## Rules that never change

- Never start, resume or speed up a campaign without the approval of the person you work for, given with the exact number of recipients and the pace. Those calls ask that person themselves in clients that support it; ask anyway.
- Pausing never waits: pause as soon as something looks wrong. Cancel only when the person decides the campaign must not go on: a cancelled campaign can't be resumed.
- An unsubscribe, a spam report or a hard bounce stops an address. Never try to add such a person back.
- A refused call says why in its error. Tell the person what it says rather than retrying the same call.
- Answers never show a person's IP address or browser; they stay on the server.

## The workflow

1. Write the email in your own working folder (or start from a template: `list_templates`, `get_template`).
2. Upload it as a template or a campaign's email (`create_upload_link`, then `create_template`, `create_campaign` or `add_revision`).
3. Open it in a browser (`get_preview_link`) after every new revision.
4. Send a test (`send_test`) and read whether it went (`get_test_sends`).
5. Set the campaign's lists, audience, sender and pace (`create_campaign`, `update_campaign`), and count who it reaches (`preview_audience`).
6. Run the pre-send checks (`run_checks`, then `get_check_results`).
7. Read the sending domain's standing (`get_overview`).
8. Ask the person to approve the recipient count and the pace, with the checks' results and the domain's numbers in hand.
9. Start or schedule it (`start_campaign`) with that count, and read `get_campaign_status` until it is sending or scheduled.
10. Watch it (`get_campaign_status`, `get_campaign_report`), and pause it the moment something looks wrong.

## Lists and people

- `list_lists` shows the lists (archived ones only when asked); `list_subscribers` pages through people with filters; `get_subscriber` shows one person, their lists and their latest consent records.
- `create_list`, `update_list` and `archive_list` work on static lists only. A dynamic list comes from the app's own records and config, which decide who is on it. An archived list is hidden from the list tools and the overview and closed to additions here, while its people stay on it, the app's own sign-ups may still join it, and campaigns, filters and saved audiences that name it go on. `archive_list` with `archived: false` restores it.
- `add_contacts` adds up to 100 people to a static list, and you name the basis the app emails them on: `consent` (they asked), `soft_opt_in` (an existing customer, where the law allows it) or `imported` (from another system). The consent records keep it as the reason. Adding never clears someone's opt-out or block, and someone kept only so they are never mailed gains nothing.
- On a double opt-in list, a contact you add waits as `pending` and no tool sends them the confirmation email: only the app's own sign-up does, so a list of addresses never mails people who didn't ask.
- `remove_contacts` takes up to 100 people off a static list. Taking them off and adding them again starts afresh.
- `set_contact_fields` sets a person's custom fields: names of letters, digits and underscores that don't start with a digit (64 characters at most), values text up to 1,000 characters, whole numbers or booleans, at most 100 a person; null removes one. A field the app keeps in step for its own records is written again by its next sync.
- `unsubscribe_contact` opts a person out of all the app's marketing; `block_contact` blocks the address by hand. Both are recorded with who did it.
- More than 100 contacts go in a CSV: `create_upload_link` with purpose `contacts`, send the file, then `preview_contacts_import` and, after the person has seen the preview's numbers, `run_contacts_import` on the same upload. The CSV's first line names its columns: `email` (required), `name`, `first_name`, `locale`, `timezone`, `country`, `last_opened_at`, `last_clicked_at`, `last_active_at`, `opted_out`, `blocked`, `custom_fields.<name>` for a custom field and `meta.<name>` for a value kept on the consent records; commas, semicolons or tabs; UTF-8. At most 2,000 contacts a file: a larger list is imported on the server, by a command of the app's own. The preview never uses the upload up; the run does. Problems name rows by number, never addresses.

## Files: uploads and downloads

- `create_upload_link` gives a one-time address for one file, its `max_file_size` for that purpose, and the curl command that sends it: `curl --fail-with-body --upload-file '<file>' '<url>'`. A curl older than 7.76 needs `--fail` instead; in Windows PowerShell type `curl.exe`, since a bare `curl` there is another command. The file name may hold no quote.
- Purposes: `email` (an HTML file or a zip, for `create_template`, `create_campaign` and `add_revision`), `image` (JPEG, PNG, GIF or WebP, for `add_image`) and `contacts` (a CSV).
- The link takes one file within 15 minutes, and the file then waits a day for the tool that uses it. A refusal before the file is stored (too large, say) leaves the link usable until it expires; once a tool reads the upload it is used up, accepted or refused, so a refused file goes up again through a new link.
- `get_template` and `get_campaign` give links, valid 60 minutes, to download an email as `email.zip` (the layout an upload takes) or its default language's `email.html`. They are always downloads, never pages.

## Writing an email

- An upload is one HTML file, or a zip holding `email.html` (the default language), an optional `email.txt`, `translations/<locale>.html` with an optional `translations/<locale>.txt` for each other language, and an `images/` folder. One folder wrapping everything is stepped into, and `__MACOSX/`, `.DS_Store` and `Thumbs.db` are skipped. A name given twice, or a translation in the default language beside `email.html`, is refused. At most 200 files and 50 MB unpacked, and 1 MB for each HTML or text file.
- Without a `zip` command: `tar.exe -a -cf email.zip email.html email.txt translations images` on Windows, or `python -m zipfile -c email.zip email.html email.txt translations images`, naming only the files and folders you have. Not Windows PowerShell's `Compress-Archive`: it writes folder paths with backslashes, which the upload refuses.
- Images referenced by a relative path (`images/hero.png`) join the asset library and their paths become its public addresses, so the working folder previews the same way locally. That holds wherever the email loads them, Outlook's conditional comments and VML, `<picture>` sources, `background` attributes and CSS `image-set()` included. `add_image` adds one from an image upload, inline as base64 (up to 4 MB), or from an https address; `list_assets` finds them. Every image must be on https, and none is ever deleted, since delivered mail keeps loading them.
- Each language's subject is the one you pass for its locale, else the HTML's `<title>`. A preheader is only the one you pass. Subjects and preheaders aren't files: `get_template` names them, and an edit passes them again.
- At most 20 languages. The default language must be there. Elements may nest at most 200 deep.
- Placeholders are a fixed set in double braces; `get_overview` lists them with this app's values. Anything else in double braces is refused, since it would reach every inbox as written. A subject or preheader holds only `{{first_name}}`, `{{name}}` and `{{email}}`. `{{first_name|there}}` gives a fallback. `{{footer}}` puts in who the email went to, the unsubscribe and privacy links and the postal address, in the email's language where the app has the package's strings in it, else in the app's language. `{{preheader}}` places the preheader; without it, the preheader goes in a hidden block at the start of the body. `{{web_version_url}}` is the email as a web page.
- Every email needs an unsubscribe link in its HTML and its text (`{{footer}}`, `{{unsubscribe_url}}` or `{{preferences_url}}`).
- At most 90 KB of HTML once filled in, since Gmail clips longer emails.
- A plain text you write travels in the zip; one you leave out is generated from the HTML each time.
- For mail apps everywhere (Outlook included): tables 600 pixels wide, inline styles, no scripts or forms, alt text on every image, and links that work without tracking.

## Templates and revisions

- `list_templates` lists the package's starter templates first, then the app's (archived ones only when asked). A template's key reads, revises and copies it even when archived.
- The starter template's button has a second link inside an Outlook-only block (`<!--[if mso]>`), which the checks and the UTM tags never see: change both links.
- `create_template` makes one from an upload, inline content, another template or a starter. `update_template` changes its name, description or default mark.
- Every upload or edit is a new revision (`add_revision`, for a template or a draft campaign); nothing is overwritten. `list_revisions` and `restore_revision` bring an older one back as a new revision.
- The edit loop: `get_template` (or `get_campaign`), download the zip or the HTML within the hour, edit it, and send it back to `add_revision` with the subjects and preheaders `get_template` named.
- `archive_template` hides a template from the list.

## Checks and previews

- `get_preview_link` gives a link, good for a week, to a page showing the email at desktop and phone width with made-up details, its plain text and its latest check results. Nothing in the email can run there, and its links open the real pages while changing nothing. Make a new one after every revision; a read-only caller may make one too.
- `run_checks` queues the pre-send checks on an email's current revision: the languages, the unsubscribe and privacy links, the size, the images (alt text, https) and every link (fetched from the server), and a spam score where the app turns it on. `get_check_results` reads them; name a check to page through its problems. The checks report and never block (only the revision rules refuse); the person decides with the results in hand.

## Drafting a campaign

- `list_campaigns` lists the campaigns by status; `get_campaign` reads one with its settings and current email.
- `create_campaign` makes a draft from a template (or a starter), an upload or inline content, with its settings now or later.
- `update_campaign` changes a draft's settings: only those given change; null clears `from_name` or `reply_to`; `pace` and `thresholds` merge into what it has; `utm` is true for the default UTM tags, false for none, or the tags themselves. Once a campaign starts, only its pace changes, through the pace tools.
- Its email changes through `add_revision` with `campaign`, while it is a draft. A start freezes it, its links carrying the UTM tags.
- Its audience: the target `lists` (everyone subscribed on any of them, once each), narrowed by any of a named audience (`list_audiences` shows the app's, with their parameters as a JSON Schema, given as `{"audience": {"name": "...", "parameters": {...}}}`), a saved audience (a named set of filters, `save_audience`), the built-in filters, people by id or address, and app records by dynamic list. Replacing a saved audience changes every campaign that names it and hasn't started.
- `preview_audience` counts who it reaches: the recipients, who was dropped and why (blocked, opted out, sunset), and the breakdown by list, language, engagement tier and mailbox provider. Opted-out, blocked and sunset people are never mailed, whatever the audience says.
- `send_test` sends the email to the app's test addresses only (it asks the person first): one per address and language, at most 25 a call, in the default language unless you name others. A draft's test is its current email; a started campaign's is the frozen one it sends. Read whether each went with `get_test_sends`. Refused while sending is switched off.
- `duplicate_campaign` copies a campaign's settings, audience and email into a new draft.

## Before a start

Ask the person to approve, and give them:

- the recipient count from `preview_audience`, and who was dropped and why;
- the pace: `first_day` (sends on day 1), `daily_growth` (percent more each day), `daily_cap`, `per_minute`, `sending_hours` and `timezone`; left out, each follows the app's config;
- the checks' results, with anything that failed;
- the sending domain's standing from `get_overview`: its last 24 hours, its daily cap and what is left, and the provider's last 30 days. Compare the last 24 hours with the limits the stop-loss holds the domain to: the app's own hard-bounce and spam-report limits, whatever a campaign sets (`get_campaign_status` shows them as `limits` for a campaign without its own `thresholds`: by the package's defaults, a pause at 2 spam reports before 2,500 sends and above 0.08% after, and at 10 hard bounces, or 5% of at least 50 sends, before 200): a domain near them pauses a new campaign at its first bounce or spam report, so wait and ask. If you can read the domain's Gmail spam rate (Google Postmaster Tools), it should be under 0.1% and never reach 0.3%;
- the test email, as it arrived.

A new or quiet sending domain starts slowly: a small first day that grows each day, so mailbox providers learn it.

## Starting and scheduling

- `start_campaign` takes the campaign's key, `recipients` (the count the person approved, from `preview_audience`), and optionally `at` (an ISO 8601 time with its offset, at most a year ahead; a time up to 15 minutes past starts it now, one further back is refused) and its `pace`.
- It answers at once with status `starting` while the audience and email are frozen. Read `get_campaign_status` until it is `sending`, `scheduled`, or a `draft` with `start_error` (the count changed, say: preview again and ask again).
- A scheduled campaign is frozen when scheduled and starts at its time. People who unsubscribe or are blocked in between are still skipped when their turn comes.
- Starting is refused while sending is switched off.

## The pace and the allowance

The pump runs every minute and hands each sending campaign's next emails to the queue. What a campaign may hand out in a minute is the least of:

- its `per_minute` rate less what it handed out this calendar minute (halved for ten minutes after the provider took nothing, a rate limit or an outage: `rate_halved_until`);
- its day's volume less what it sent that day, day n being the nth 24 hours from its start (or from a resume after a pause of more than a day), the volume growing by `daily_growth` from `first_day` up to `daily_cap`;
- the sending domain's daily cap less its last 24 hours across every campaign on it and the double opt-in emails;

and nothing outside its `sending_hours` in its `timezone`. `get_campaign_status` shows the pace in effect, the day, its volume and what it may hand out now. The pump does nothing while sending is switched off; a scheduled campaign whose time passes then starts once sending is switched back on.

## Watching a campaign

- `get_campaign_status`: its status and times, why a start failed or a pause happened, its messages by status, its failures by kind, the queue (with a stall: emails queued for more than five minutes, when no worker takes them and the campaign hands out nothing more until they go), and the pace and limits in effect.
- `get_campaign_report`: its sends, deliveries, failures, hard bounces, spam reports and unsubscribes (by how they came: a mail app's one-click, the page, the provider), opens and clicks by people and by machines (Apple's privacy opens and link scanners don't count as reading), the rates, the most clicked links, and the same by list, language, engagement tier and mailbox provider, with a timeline by day. It is worked out when read and keeps moving after the campaign completes.
- Failures by kind, and what each did to the address:
  - `hard_bounce`: the address doesn't exist; it is blocked.
  - `soft_bounce`: a full mailbox or a server that gave up; three in a row block the address.
  - `sender_blocked`: the receiving server refused the sender (a reputation or policy block), nothing against the address. Many of these mean stop and look at the domain.
  - `suppressed_bounce`, `suppressed_complaint`, `suppressed_unsubscribe`: the provider held the address from before and sent nothing; the first two block it, the last opts the person out.
  - `other`: anything else permanent.
  - `not_accepted`: the send ended unclear and the provider's logs held nothing for it an hour later; it is never resent.
- A message `unknown` ended unclear. The reconciler looks it up in the provider's logs from 15 minutes on: it becomes sent when they show it went, or `not_accepted` when they hold nothing an hour later. One too old to look up stays `unknown`. None is ever resent.
- A spam report blocks the address and opts the person out of everything; an unsubscribe opts them out of all the app's marketing.

## The stop-loss

The package pauses a campaign on its own, measuring per send (an email the provider took), from the campaign's messages:

- early on, a handful of hard bounces, or a share of them once enough have gone; later, more than the limit among its latest sends;
- spam reports: a count early on, then a share;
- unsubscribes: a share, once enough have gone.

`get_campaign_status` shows the limits in effect (`limits`); a campaign may set its own `thresholds` before it starts. The sending domain's last 24 hours across all its campaigns are held to the app's own bounce and spam report limits whatever a campaign sets, and crossing them pauses every sending and scheduled campaign on the domain. The pause's reason names the numbers and the limit's key.

Resuming after a campaign's own pause counts its limits again from that pause, so it isn't paused again for the same numbers. A domain pause starts the domain's numbers again but not each campaign's own, so a campaign that was near its own limits may pause at its next bounce. And while the domain's last 24 hours are over its limits with nothing sending (no pause happened, so nothing started again), a campaign started or resumed on it pauses at its first bounce or spam report. Tell the person why it paused before asking to resume.

The app's alert address (where set) gets an email for an automatic pause, a campaign's first spam report, and a stalled queue.

If the provider refuses the sending domain itself (a key or domain problem), the email goes back to waiting and every campaign on the domain pauses until someone resumes it.

## Pausing, resuming, changing the pace, cancelling

- `pause_campaign` stops handing out emails at once (with an optional reason); queued emails wait. It never needs approval and works while sending is off.
- `resume_campaign` carries on where it stopped, after the person's approval. One paused before its scheduled time that is still ahead waits for it again.
- `slow_down_campaign` takes any change that sends no faster; `speed_up_campaign` takes any change, after the person's approval. Only the values given change.
- `cancel_campaign` stops a campaign for good (in any status but completed); its unsent emails stay counted, and it can't be resumed. `duplicate_campaign` makes a new draft that targets the same people again, those already reached included: before previewing it, add the filter `{"campaigns": [{"campaign": "<old key>", "outcome": "not_received"}]}` to its audience, keeping the audience's other parts.

## What runs on its own

- The pump, every minute: starts scheduled campaigns, hands out each sending campaign's next emails, and completes a campaign with nothing left.
- The reconciler, every 15 minutes: puts back emails whose job was lost, replays the provider's logs from half an hour behind (deliveries and failures a webhook missed), and looks up sends that ended unclear. It runs while sending is off too.
- Pruning, daily: the provider's events older than the app's retention (180 days unless it sets another) go once their campaign is completed or cancelled and its report kept; a campaign still sending or paused keeps its own.

## Unblocking

`unblock_contact` (it asks the person first) clears a block here and says whether the provider still holds the address on the sending domain's bounce, complaint or unsubscribe list, or that it couldn't check. Removing it there is a change at the provider, which the person makes or approves; until then the provider sends it nothing. Soft bounces start over at 0. Unblock only on good evidence: an address that bounced or complained hurts the domain if mailed again.

## Server commands

Large or sensitive jobs are commands the person runs on the app's server, never tools here: importing a large list from another system (a command of the app's own, with a dry run first), `campaigns:import-provider-suppressions <domain>` (the provider's bounce, complaint and unsubscribe lists as blocks and opt-outs; a dry run unless `--force`), `campaigns:sync-list <list>` (a dynamic list put back in step with the app's records) and `campaigns:token` (this server's access tokens). Ask the person to run them.
