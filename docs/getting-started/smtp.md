---
title: SMTP
weight: 3
---

The `smtp` driver sends through any SMTP server: a relay in production, or a mail catcher on your own machine. It has no settings of its own and no feedback path to set up, since SMTP brings nothing back after the handover ([below](#what-it-reads-back)). It sends through the `campaigns` mailer, which must use Laravel's `smtp` transport:

```php
'mailers' => [
    // ...

    'campaigns' => [
        'transport' => 'smtp',
        'host' => env('CAMPAIGNS_SMTP_HOST'),
        'port' => env('CAMPAIGNS_SMTP_PORT', 587),
        'username' => env('CAMPAIGNS_SMTP_USERNAME'),
        'password' => env('CAMPAIGNS_SMTP_PASSWORD'),
    ],
],
```

```dotenv
CAMPAIGNS_DRIVER=smtp
CAMPAIGNS_SMTP_HOST=smtp.your-relay.example
CAMPAIGNS_SMTP_PORT=587
CAMPAIGNS_SMTP_USERNAME=your-username
CAMPAIGNS_SMTP_PASSWORD=your-password
```

The `CAMPAIGNS_SMTP_*` names are your app's own, read only by this mailer. It takes Laravel's other `smtp` options as any smtp mailer does: without a `scheme`, port 465 uses TLS from the start and other ports switch to TLS when the server offers it.

Any other transport is refused, except the log and array mailers outside production. When the mailer sets no `timeout`, the package gives it 10 seconds for each read of the server's reply, so a server that stops answering doesn't hold the send job until its own 60-second limit. The `X-Tag` and `X-Metadata-*` headers Laravel's mailables add are left out, since an SMTP server would deliver them as they are.

## What it reads back

SMTP tells the sender only what the server answers while the email is handed over, and the driver reads the reply code and the command it answered, never the server's words:

| The server answers | What happens |
|---|---|
| 4xx | it took nothing: the email waits and goes later, and the campaign's rate halves for ten minutes |
| 5xx while connecting (a refused login) or to the sender | every campaign on the sending domain pauses until someone acts |
| 5xx to the recipient or the email, naming a mailbox that doesn't exist (the enhanced codes 5.1.1, 5.1.2, 5.1.3 and 5.1.10) | the email fails as `hard_bounce` and the address is blocked |
| any other 5xx | the email fails as `rejected` and the address is left alone; when a campaign's last 10 sends were all refused this way, it pauses |
| nothing at all, once the email was being sent | the email is `unknown` and never resent |

A server that can't be reached took nothing. Before each email, the driver checks the connection a queue worker kept open and starts a new one when the server dropped it. That check takes the place of Symfony's own restart after every 100 emails and its idle ping, which the driver turns off on that mailer. An address with characters outside ASCII before the @ fails as `rejected` when the server doesn't offer SMTPUTF8, since nothing else can carry it.

Nothing comes back after that. A bounce the receiving server mails later goes to the sender's inbox, not to the package, and neither spam reports nor an unsubscribe made at the service reach it. So with this driver:

- every email ends at `sent`, never `delivered`;
- the stop-loss sees only what's refused while sending, so a list full of dead addresses at another provider can hurt the domain before anything pauses;
- the report divides opens and clicks by sent emails (`rates.basis` is `sent`);
- `campaigns:import-provider-suppressions` and `unblock_contact` have no lists to read.

The package's own unsubscribe page and the one-click header work as with any driver. `get_overview` warns about all of this, and so do `start_campaign` and `resume_campaign`.

## In production

Send through a relay that is allowed to send for your sending domain, with SPF, DKIM and DMARC set up for it as your relay's docs describe ([Installation](./installation.md#choose-the-sending-domain)), never straight from your web server. Since bounces don't come back, start with a list you know is clean. If you're moving from Mailgun, run `campaigns:import-provider-suppressions` while Mailgun is still the driver, since it reads only the active driver's lists. For an old or large list, prefer a driver that reads feedback, such as [Mailgun](./mailgun.md).

## On your own machine

A mail catcher keeps every email so you can read it as a mailbox would. [Mailpit](https://mailpit.axllent.org) is one:

```bash
docker run -d --name mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Keep the mailer above and point it there, with no username or password:

```dotenv
CAMPAIGNS_SMTP_HOST=127.0.0.1
CAMPAIGNS_SMTP_PORT=1025
```

Mailpit shows the emails at `http://127.0.0.1:8025`. Laravel Herd Pro's mail service works the same way: use the SMTP port its mail settings show, and set `CAMPAIGNS_SMTP_USERNAME` to a name such as your app's, with no password: Herd only files the emails under that name.

A test email needs less than the whole [installation](./installation.md): the package installed, this mailer, the sender, a worker for the `campaigns` queue and a token for your agent. The scheduler, the image disk and the cache store can wait until you send campaigns.

Point `CAMPAIGNS_URL` (or `APP_URL`) at the address you serve the app on. An email's unsubscribe and web version links start with it, so they open in your browser, and so do its [open pixel and tracked links](../campaigns/tracking.md): open an email in Mailpit and its open is recorded, click a link and the click is, each applied about a minute later. Images must be on https, which on the default `public` disk means serving the app over https (`https://your-app.test` with Herd's `herd secure`, say); over plain http, such as `php artisan serve`, everything else works.
