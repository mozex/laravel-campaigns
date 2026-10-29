---
title: Engagement and the Sunset
weight: 4
---

Every subscriber has an engagement tier, worked out from the latest of their last human open, last click and last activity in your app (`Campaigns::recordActivity()`):

| Tier | When | Default |
|---|---|---|
| engaged | within `engaged_days` | 90 days |
| active | within `active_days` | 365 days |
| silent | anything older, or never | |

Tiers order a campaign's sends (engaged people first), filter audiences, and break down the overview's counts and every report. They're worked out when read, never stored, so a new open moves someone at once.

## The sunset

Mailing people who never read hurts a domain's reputation: mailbox providers watch how many recipients ignore it. An address stays mailable until it's been silent for `sunset_months` (12 by default) since the later of its last engagement and the first campaign email your app sent it. After that, campaigns skip it.

- Any engagement ends a sunset at once, `recordActivity()` included.
- An address your app has never mailed is never sunset, however old its imported dates.
- `'sunset_months' => null` turns the sunset off.

A sunset person stays on their lists; `preview_audience` counts them among those dropped, and a filter can find them.

## Imported engagement

An import can carry `last_opened_at`, `last_clicked_at` and `last_active_at` from another system, so moving from another email service doesn't make every reader look silent. Imported dates only ever move a person's engagement forward.
