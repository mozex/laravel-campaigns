---
title: Audiences
weight: 2
---

A campaign goes to everyone subscribed on its target lists, once each, narrowed by anything else its audience names. Every part narrows; none widens.

## The parts

| Part | Narrows to |
|---|---|
| `lists` (a campaign setting) | people subscribed on any of these lists |
| `audience` | a [named audience](../agents/your-data.md#named-audiences) of your app's, with its parameters |
| `saved_audience` | a saved set of filters, by key |
| `filters` | the built-in filters below |
| `subscribers`, `emails` | these people, by subscriber id or by address |
| `records` | these app records, by the key of the dynamic list whose source holds them |

Each part names at most 10,000 people or records. Addresses become subscriber ids when the audience is set, so a stored audience holds no addresses, only how many matched nobody. A list of people that names nobody reaches nobody, never everyone.

## Filters

| Filter | What it matches |
|---|---|
| `lists`, `list_status` | people on these lists, `subscribed` unless another status is asked for |
| `locales`, `countries` | by language or country |
| `tiers` | by engagement tier: `engaged`, `active`, `silent` |
| `opted_out`, `blocked`, `sunset` | by those states, to count or find them |
| `custom_fields` | by a custom field's value |
| `dates` | `created`, `last_opened`, `last_clicked`, `last_active`, `first_mailed`, `opted_out` or `blocked`, after or before a time |
| `campaigns` | what someone did with an earlier campaign: `received`, `not_received`, `opened`, `not_opened`, `clicked`, `not_clicked` |

An unknown filter, list or campaign is refused rather than matching nobody, so a typo never quietly empties a campaign. Each filter takes at most 100 values, and a blank date is refused rather than read as now.

A saved audience is read when the campaign starts, so replacing it changes every campaign that names it and hasn't started yet.

## Who is never mailed

Whatever an audience says, a campaign never mails someone opted out, blocked, or past the [sunset](../lists-and-subscribers/engagement.md), and only `subscribed` list subscriptions count, so a pending double opt-in gets nothing. That's checked at the freeze and again when each email's turn comes.

## Previewing

`preview_audience` counts who a campaign would reach before anything is saved or started: the recipients, who was dropped and why (blocked, then opted out, then sunset, so the reasons add up), and the breakdown by list, language, engagement tier and mailbox provider. The preview, the start's count and the freeze run one query, so the number the person approves is the number frozen.
