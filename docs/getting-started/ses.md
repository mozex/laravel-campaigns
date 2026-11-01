---
title: Amazon SES
weight: 4
---

The `ses` driver sends through Amazon SES's v2 API. It needs the AWS SDK for PHP, version 3.393.3 or later, which the package only suggests:

```bash
composer require aws/aws-sdk-php
```

It sends through the `campaigns` mailer, which must use Laravel's `ses-v2` transport with the SDK's retries turned off:

```php
'mailers' => [
    // ...

    'campaigns' => [
        'transport' => 'ses-v2',
        'key' => env('CAMPAIGNS_SES_KEY'),
        'secret' => env('CAMPAIGNS_SES_SECRET'),
        'region' => env('CAMPAIGNS_SES_REGION', 'eu-west-1'),
        'retries' => 0,
        'options' => [
            'ConfigurationSetName' => env('CAMPAIGNS_SES_CONFIGURATION_SET'),
        ],
    ],
],
```

```dotenv
CAMPAIGNS_DRIVER=ses
CAMPAIGNS_SES_WEBHOOK_SECRET=a-long-random-value
```

The SDK retries a request whose answer it lost three times by default, and SES may already have sent that email, so a person could get it twice. A mailer without `'retries' => 0`, on itself or in `services.ses`, is refused: every campaign on the domain pauses until it's set. When neither gives `http` options, the package sets a 10-second connect timeout and 30 seconds in all, so a stuck request ends before the send job's 60 seconds. Any other transport is refused, except the log and array mailers outside production.

Each email carries its uuid as an SES message tag (`campaign-message`), which SES puts back in every event about it. Laravel's `X-Tag` header is left out. SES's own open and click tracking is turned off on every email, whatever the account's or the configuration set's settings say: the package already tracks both ([Opens and Clicks](../campaigns/tracking.md)). An older SDK can't send that switch, so the driver refuses it.

## Set up SES

Do these in the AWS console, in the region the mailer names:

1. **The identity.** Verify your marketing domain (`news.example.com`) with Easy DKIM, and give it a custom MAIL FROM domain so SPF aligns too.
2. **The configuration set.** Create one and name it in the mailer's `ConfigurationSetName`. Add an event destination of type Amazon EventBridge with these event types: Sends, Deliveries, Hard bounces, Complaints, Rejects and Delivery delays. Leave Opens and Clicks out; the driver drops any that arrive. Without a configuration set (or a default one on the identity), no event ever reaches the package, and nothing warns you.
3. **The connection.** In EventBridge, create a connection with API key authorization: the key name `X-Campaigns-Webhook-Key` and the value of `CAMPAIGNS_SES_WEBHOOK_SECRET`.
4. **The API destination.** Point it at `https://your-app.example.com/campaigns/webhooks/ses` with method POST, using that connection.
5. **The rule.** On the default event bus, add a rule with the API destination as its target, matching only this configuration set's sending events, so another app's mail on the same account never reaches yours. Send the event as it is, with no input transformer:

   ```json
   {
     "source": ["aws.ses"],
     "detail-type": ["Email Sent", "Email Delivered", "Email Bounced", "Email Complaint Received", "Email Rejected", "Email Delivery Delayed"],
     "detail": {"mail": {"tags": {"ses:configuration-set": ["your-configuration-set"]}}}
   }
   ```
6. **Production access.** A new account is in the sandbox: 200 emails a day, one a second, and only to addresses you verified. Ask AWS for production access in that region before your first campaign.

The credentials in the mailer need `ses:SendEmail` and `ses:SendRawEmail` to send, `ses:ListSuppressedDestinations` and `ses:GetSuppressedDestination` for the suppressions import and `unblock_contact`, and `ses:BatchGetMetricData` when VDM is on.

EventBridge posts from AWS's servers, so a CDN or firewall in front of the app must let `POST /campaigns/webhooks/ses` through without a browser or bot check.

To try it without real people, send to SES's mailbox simulator: `success@simulator.amazonses.com` gets a delivery, `bounce@simulator.amazonses.com` a hard bounce and `complaint@simulator.amazonses.com` a spam report. It works in the sandbox, and SES bills those emails like any other.

## What it reads back

| SES reports | What happens |
|---|---|
| Send | an email whose send ended unclear is marked sent |
| Delivery | the email is `delivered` |
| a permanent bounce, SES's global suppression list included | the email fails as `hard_bounce` and the address is blocked |
| a bounce because the address is on the account's suppression list | the email fails and the address is blocked, without counting toward the stop-loss's bounce rate, as SES doesn't count it either |
| a transient bounce (a full mailbox, say), sent once SES stops trying | one soft bounce against the address; three in a row block it |
| a bounce about the email itself (too large, its content or an attachment refused) | the email fails, nothing against the address |
| a complaint | the address is blocked and opted out; an `auth-failure` or `not-spam` report changes nothing |
| a complaint because the address is on the account's suppression list | the address is blocked; the stop-loss doesn't count it, since nobody complained about this email |
| Reject (a virus) | the email fails, nothing against the address |
| Delivery delay | nothing yet; when the delay was the receiving server taking the mail for spam or refusing SES's address, or SES's own trouble, a bounce that follows counts nothing against the address |

When SES refuses an email as it's sent, the driver reads the AWS error code and the HTTP status:

| SES answers | What happens |
|---|---|
| throttling (429, `ThrottlingException`, the daily quota among them), a 408, or a 500, 502 or 503 | it took nothing: the email waits and goes later, at half the rate for ten minutes |
| a suspended or paused account, an unverified MAIL FROM domain, `MessageRejected` (an unverified From address, or content SES refuses), `LimitExceededException`, a configuration set it doesn't have, or credentials it refuses (401, 403, 404) | the email waits and every campaign on the sending domain pauses until someone acts |
| `BadRequestException` | the email fails as `rejected`; 10 in a row pause the campaign |
| no answer, a 504, or anything else | the email is `unknown` and never resent |

SES keeps no logs the package can read. An event EventBridge couldn't deliver is lost, and an email whose send ended unclear stays `unknown` unless its Send or Delivery event arrives. EventBridge retries a failed delivery for up to 24 hours and waits at most 5 seconds for each answer, so keep the webhook reachable; a dead-letter queue on the rule's target shows you what was lost, though the package can't read it.

SES has no unsubscribe of its own here: the package's page and the one-click header handle it, as with any driver.

## Suppressions and daily counts

SES keeps one suppression list for the whole account in each region, holding bounces and complaints, whatever domain sent the mail. `campaigns:import-provider-suppressions` reads all of it, whichever domain you pass. SES compares addresses on that list as they were added, so `unblock_contact` can miss one added in other letter cases.

`get_overview`'s `domain.provider` shows SES's sends, hard bounces and complaints by day only with Virtual Deliverability Manager, a paid add-on. Turn it on in SES, then set:

```dotenv
CAMPAIGNS_SES_VDM=true
```

Without it, the overview notes that the counts need VDM. SES counts no forwarded mail and no unsubscribes, so those stay null.
