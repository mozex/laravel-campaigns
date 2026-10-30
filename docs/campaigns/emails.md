---
title: Emails and Templates
weight: 1
---

Agents write emails as HTML on their own machine and upload them. The package keeps every version, checks each one before it's saved, and fills in each recipient's details when it sends.

## Templates and revisions

A template is a reusable email; a campaign has its own email, often copied from one. Either way the email is a series of numbered revisions: every upload or edit adds one, nothing is overwritten, and restoring an old version adds a copy of it as the newest. A campaign's email changes only while it's a draft. When it starts, the current revision is frozen, its links to your own sites carrying [UTM tags](#utm-tags), so the web version and reports show exactly what went out.

The package ships a starter template, kept as a file so a package update can improve it without touching your templates. `list_templates` lists it first, then your app's.

## The upload

An email is one HTML file, or a zip:

```
email.html                  the default language
email.txt                   optional plain text
translations/de.html        another language
translations/de.txt         optional
images/hero.png             referenced as images/hero.png
```

- Images referenced by a relative path join the asset library on `campaigns.assets.disk`, and their paths become its public https addresses, wherever the email loads them: Outlook's conditional comments and VML, `<picture>` sources, CSS `image-set()` and the rest of the forms listed under the rules below. Every image must end up on https; SVG is refused. Images are named by their content and never deleted, since delivered mail keeps loading them.
- One folder wrapping everything is stepped into, and `__MACOSX/`, `.DS_Store` and `Thumbs.db` are skipped. The zip is read in memory and never unpacked to disk; at most 200 files and 50 MB unpacked, 1 MB per HTML or text file.
- A language's subject is the one the agent passes for it, else the HTML's `<title>`. Preheaders are only what's passed.
- A plain text that's left out is generated from the HTML, each time it's needed.

## Placeholders

Placeholders are a fixed set, filled in one pass and never compiled, so a recipient whose name is `{{footer}}` gets their name, not a footer, and uploaded HTML can't run code.

| Placeholder | Becomes |
|---|---|
| `{{first_name}}` | the recipient's first name; `{{first_name\|there}}` gives a fallback |
| `{{name}}` | the recipient's full name |
| `{{email}}` | the recipient's address |
| `{{unsubscribe_url}}`, `{{preferences_url}}` | the page where they unsubscribe from everything, or subscribe again (one page) |
| `{{web_version_url}}` | the email as a web page |
| `{{footer}}` | who the email went to, the unsubscribe and privacy links and the postal address |
| `{{postal_address}}`, `{{privacy_url}}` | from `campaigns.postal_address` and `campaigns.privacy_url` |
| `{{preheader}}` | where the preheader goes; without it, the preheader goes in a hidden block at the start of the body |

A subject or preheader takes only `{{first_name}}`, `{{name}}` and `{{email}}`. Values are escaped in the HTML and put in as they are in the text. `get_overview` lists every placeholder with your app's values.

The footer is a Blade view you can publish and restyle (`php artisan vendor:publish --tag=campaigns-views`). It speaks the email's language where your app has the package's strings in it (a `pt_BR` version takes `pt` strings too), else your app's language. The package ships English; publish the strings (`--tag=campaigns-translations`) to add others.

## The rules every revision meets

A revision that breaks any of these is refused, with every problem listed in one error. The one exception is an upload whose HTML is over twice the size limit as written: it's refused for that alone, before its images are read.

- at most 20 languages, the default language (`campaigns.locale`) among them, none given twice however written (`EN`, `pt-br`);
- a subject on one line;
- nothing in double braces that isn't a known placeholder (`{{frist_name}}` would reach every inbox as written), no triple braces, and no placeholder whose braces a tool percent-encoded (`%7B%7Bweb_version_url%7D%7D`, which CSS inliners built on libxml write in links);
- an unsubscribe link in the HTML and the text (`{{unsubscribe_url}}`, `{{preferences_url}}` or `{{footer}}`);
- every image on https, inside Outlook's conditional comments (`<!--[if mso]>`) too: `src`, `srcset` and `lowsrc` on `img`, `srcset` on `<picture>`'s `source`, `src` on an image button and on VML (`v:image`, `v:fill`, `v:imagedata`), `poster` on `video`, `href` on SVG's `image`, `background` on any element, and CSS `url()`, `image-set()` and `@import` in style attributes and style elements;
- elements nested at most 200 deep, since the HTML parser behind these rules stops reading at 256 and would miss the rest;
- at most 90 KB of HTML once filled with sample values, since Gmail clips at 102 KB and click tracking lengthens links, and at most 90 KB of text.

## Pre-send checks

`run_checks` queues checks on a revision, and `get_check_results` reads them. They report and never block: the person deciding whether to send sees them.

| Check | Looks at |
|---|---|
| `locales` | languages a campaign's audience speaks that the email lacks |
| `unsubscribe`, `privacy_link` | the links every email needs |
| `size` | the HTML's size, counting what click tracking adds |
| `images` | alt text and https |
| `links` | every link fetched from the server (up to 100 addresses or two minutes), the package's own token pages left out |
| `spam_score` | a SpamAssassin score from Postmark's free checker, when `campaigns.checks.spam_score` is on |

## Previews and test emails

`get_preview_link` makes a link, good for a week, to a page showing the email at 600 and 375 pixels wide with made-up details, its plain text and its latest checks. Nothing in the email can run there, and its links open the real pages without changing anything, so the link is safe to pass around.

`send_test` sends the email to `campaigns.test_addresses` only, one per address and language, at most 25 a call. A draft's test is its current revision; a started campaign's is the frozen one it sends. Test emails carry their own tag, so Mailgun's numbers never mix them with a campaign's.

## UTM tags

When a campaign starts, links to your own sites get `utm_source`, `utm_medium` (`campaigns.utm`) and `utm_campaign` (the campaign's key). Your sites are the hosts of `APP_URL` and `campaigns.url` without a leading `www.`, and their subdomains, unless `campaigns.utm.hosts` lists others. A link that already holds a `utm_` parameter or a placeholder stays as written. A campaign can change the tags or switch them off.
