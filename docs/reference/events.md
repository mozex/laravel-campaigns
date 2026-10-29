---
title: Events
weight: 3
---

Listen to these to hook campaigns into the rest of your app: a Slack message when a campaign pauses itself, a CRM update when someone unsubscribes.

| Event | When | Carries |
|---|---|---|
| `CampaignStartedEvent` | a campaign reaches `sending` (never at scheduling) | `campaign` |
| `CampaignPausedEvent` | a campaign pauses | `campaign`, `reason`, `automatic` (true when the package paused it itself) |
| `CampaignCompletedEvent` | a campaign has nothing left to send | `campaign` |
| `CampaignQueueStalledEvent` | each minute a campaign's queued emails wait more than five minutes for a worker | `campaign`, `stalledMessages`, `oldestClaimedAt` |
| `CampaignFirstComplaintEvent` | a campaign's first spam report, while it keeps sending | `campaign` |
| `SubscriberUnsubscribedEvent` | a person is opted out of everything, or leaves the sign-up box unticked | `subscriber`, `context` |
| `SubscriberBlockedEvent` | an address is blocked | `subscriber`, `reason`, `context` |

All of them live in `Mozex\Campaigns\Events`. The campaign events and both subscriber events fire after the database transaction commits, so a listener sees the change saved, and each carries a model loaded on its own, so reading its relations is safe under strict models.

A queued listener gets the subscriber back by id: neither subscriber event puts the address or details into the queue payload. The `context` still carries the person's IP address and browser when they acted themselves, so a queued listener should leave those out of anything it stores.

A listener that throws is reported to your error tracker rather than thrown: by then the change has happened, and a broken listener mustn't fail a person's one-click unsubscribe or the sending run. For the same reason, assert on what a listener did after the action in your tests, not inside the listener.

## The alert mail

The package listens to its own events and mails `campaigns.alert_address` for an automatic pause, a first spam report and a stalled queue (at most once every six hours), through your app's default mailer and never the queue: a stalled queue would hold it, and the marketing domain may be the thing Mailgun just refused. Leave the address empty to send none.
