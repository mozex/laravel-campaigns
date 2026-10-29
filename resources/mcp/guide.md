# Running this app's email campaigns

This server runs the app's email marketing on the app's own server. Its lists are views of the app's users (dynamic lists) or contacts added by hand (static lists), its templates and campaign emails live here as numbered revisions, and its queue sends each campaign one email at a time at the pace the campaign sets.

## Start here

Call `get_overview` first. It tells you:

- whether sending is switched on, which provider and domain send, and what is switched off (`sending.notes`);
- the addresses a test email may go to;
- every list with its counts: `subscribed`, `pending` (waiting for a double opt-in), `unsubscribed`, `mailable` (subscribed people who aren't opted out, blocked or sunset) and the subscribed people by engagement tier;
- the campaigns that are starting, scheduled, sending or paused;
- the placeholders an email may hold, with this app's values. Only `first_name`, `name` and `email` may go in a subject or preheader.

## Rules that never change

- Never start, resume or speed up a campaign without the approval of the person you work for, given with the exact number of recipients and the pace.
- Pausing and cancelling never wait: do either as soon as something looks wrong.
- An unsubscribe, a spam report or a hard bounce stops an address. Never try to add such a person back.
- A refused call says why in its error. Tell the person what it says rather than retrying the same call.
