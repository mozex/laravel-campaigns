---
title: Opens and Clicks
weight: 5
---

The package tracks opens and clicks itself, the same way whichever driver sends: every email gets a one-pixel image and its links are wrapped in redirects, both served by your app at `campaigns.url`. The sending service's own tracking is turned off on every email (the Mailgun driver sends `o:tracking-opens: no` and `o:tracking-clicks: no`), and any open or click event a service still reports is dropped, so nothing is counted twice and one set of rules decides what a person did.

A campaign's `track_opens` and `track_clicks` switch each part off. A test email is tracked like its campaign's emails, and a template's test always is, so you can see tracking work before a start.

## The open pixel

The pixel goes right after the email's hidden preheader, or where the body starts when there is none, so Gmail clipping a long email can't cut it off. It loads from `/campaigns/o/{token}`, answers with a 43-byte transparent GIF and sets no cookie.

Subscribers whose `country` is in `campaigns.no_open_tracking_countries` (`['FR']` by default) get no pixel. France's data protection authority (CNIL, recommendation 2026-042) asks for consent before a tracking pixel, which the package doesn't collect. Their links are still wrapped: the CNIL's recommendation is about pixels, and reaches tracking links only indirectly.

## Wrapped links

When a campaign starts, the package keeps one row per address its email's links lead to (`campaign_links`), with a random 22-character key. Each link is then sent as `/campaigns/l/{key}/{token}`, which records the click and redirects to the row's address. The path holds only the key, so the redirect can only go where the email's own links went, and rows are never deleted, so a link in an old email keeps working after its events are pruned.

These are never wrapped:

- addresses that aren't `http` or `https` (`mailto:`, `tel:`, `#anchors`);
- links whose address holds a placeholder, such as `https://shop.example.com/?ref={{email}}`, since it differs per recipient;
- the package's own pages: unsubscribe, preferences, confirmation and web version;
- links whose visible text is itself an address (`<a href="https://example.com">https://example.com</a>`), since a reader expects to land where it says;
- links marked `data-campaigns-no-track`, an attribute the package takes off every link that carries it before the email goes out;
- addresses longer than 2,048 characters, which a redirect's header can't carry past most web servers' defaults.

Outlook's VML buttons inside conditional comments (`<!--[if mso]><v:roundrect href="...">`) are wrapped like the links beside them. The plain-text part, the web version, the preview and the pre-send checks' copy are never tracked.

```html
<a href="https://shop.example.com/sale">Shop the sale</a>
<a href="https://shop.example.com/terms" data-campaigns-no-track>Terms</a>
```

## People and machines

Only a person's open or click counts as engagement: it sets the email's `opened_at` or `clicked_at` and the subscriber's latest date, which decide engagement tiers and the 12-month sunset. Each hit is classified as it arrives, by its request alone:

| The request | Counts as |
|---|---|
| a user agent of exactly `Mozilla/5.0` | Apple Mail's privacy prefetch, a machine |
| `GoogleImageProxy` or `YahooMailProxy` in the user agent | a person: Gmail and Yahoo fetch images only when the email is opened |
| a `HEAD` request, or no user agent | a machine |
| the agent Gmail's and Apple's prefetchers have been seen sending (`Chrome/42.0.2311.135 Safari/537.36 Edge/12.246`) | a machine |
| an HTTP library (`curl`, `python-requests`, `Go-http-client` and others), a mail security gateway (Mimecast, Proofpoint, Barracuda), a headless browser, a chat app fetching a link's preview (Facebook, WhatsApp, Telegram, Skype), or a crawler (`Googlebot/2.1`, `Slackbot-LinkExpanding`) | a machine |
| anything else | a person |

Then, when the hit is applied a minute later, its timing is checked against when the email arrived (its delivery, or when it was sent for a driver that reports no delivery):

- an open within 5 seconds of arrival, or before it, is a machine's, whatever its user agent;
- a click within 10 seconds of arrival, or before it, is a link scanner's;
- a click within 3 seconds of a click on another link in the same email is a scanner opening every link.

A hit isn't classified by its IP address: Apple's prefetch leaves through networks shared with iCloud Private Relay, which carries real people's clicks too. Neither the IP nor the user agent is stored, only the class. An email records at most 30 hits a minute; past that the request is still answered. No sender catches every scanner, so expect some machine clicks to count.

The report counts every open and click, by people and by machines, and divides the open and click rates by delivered emails (sent ones, for a driver that reports no delivery), people only. Each link in it also counts `people`, the emails with a person's click on that link, which is the number to compare links by: Gmail can fetch a clicked link again a couple of seconds later with a browser's user agent, and then a Gmail reader's click counts twice in `human`, the link's and the campaign's.

## Your CDN and firewall

Keep `/campaigns/o/` and `/campaigns/l/` out of every caching rule and every bot or browser check. A cached pixel or redirect records nothing after its first hit, and a challenge page in front of them hides every open from a proxy or a mail app. The pixel's path has no file extension and answers `Cache-Control: no-store, no-cache, private, max-age=0`, and the redirect is a `302` with `no-store`, so a CDN's default rules leave both alone (Cloudflare caches only by file extension by default, and never stores a response marked `no-store`). A "cache everything" rule or an edge TTL over `/campaigns/` would override that.

## Your domain's reputation

Every tracked link now points at your app's host, so mailbox providers judge your links by that domain. Send from a subdomain aligned with your From address (`news.example.com` for `hello@news.example.com`), point `CAMPAIGNS_URL` at a host on the same domain, and keep it off blocklists.

## The size limit

Wrapping lengthens every link, so the 90 KB limit on an email's HTML counts each tracked link at its wrapped length and the pixel's tag. An email with a few hundred links can pass untracked and fail once tracked; mark the links that don't need tracking with `data-campaigns-no-track`, or switch `track_clicks` off for that campaign.

## Privacy

The EDPB's guidelines on article 5(3) of the ePrivacy directive treat tracking pixels and tracking links alike, and the CNIL asks senders to offer each recipient a way to turn tracking off. The package has no per-recipient switch: the country rule and each campaign's `track_opens` and `track_clicks` are what it offers. Name the tracking in your privacy policy, and check what your own country's rules ask for.
