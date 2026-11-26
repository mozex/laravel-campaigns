---
title: Amazon SES
weight: 4
---

The `ses` driver sends through Amazon SES's v2 API and hears back through SES's events, which Amazon EventBridge posts to your app; both are required. Its daily counts through Virtual Deliverability Manager are an [optional extra](./optional-extras.md). It needs the AWS SDK for PHP, version 3.393.3 or later, which the package only suggests:

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
        'region' => env('CAMPAIGNS_SES_REGION', 'us-east-1'),
        'retries' => 0,
        'options' => [
            'ConfigurationSetName' => env('CAMPAIGNS_SES_CONFIGURATION_SET'),
        ],
    ],
],
```

```dotenv
CAMPAIGNS_DRIVER=ses
CAMPAIGNS_SES_KEY=your-access-key-id
CAMPAIGNS_SES_SECRET=your-secret-access-key
CAMPAIGNS_SES_REGION=us-east-1
CAMPAIGNS_SES_CONFIGURATION_SET=your-configuration-set
CAMPAIGNS_SES_WEBHOOK_SECRET=a-long-random-value
```

Give the mailer credentials of its own, holding the [permissions](#permissions) below. Left empty, the AWS SDK looks in its default places (the `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` variables, a profile, the server's role), which may hold your other mail's credentials without these permissions. Any long random value does for the webhook secret, such as the output of `openssl rand -hex 32`.

The region is the one your SES identity and configuration set live in; SES keeps a separate setup, sandbox and quota in each. The SDK retries a request whose answer it lost three times by default, and SES may already have sent that email, so a person could get it twice. A mailer without `'retries' => 0`, on itself or in `services.ses`, is refused: every campaign on the domain pauses until it's set. When neither gives `http` options, the package sets a 10-second connect timeout and 30 seconds in all, so a stuck request ends before the send job's 60 seconds. Any other transport is refused, except the log and array mailers outside production.

Each email carries its uuid as an SES message tag (`campaign-message`), which SES puts back in every event about it. Laravel's `X-Tag` header is left out. SES's own open and click tracking is turned off on every email, whatever the account's or the configuration set's settings say: the package already tracks both ([Opens and Clicks](../campaigns/tracking.md)). An older SDK can't send that switch, so the driver refuses it, and the switch needs an IAM permission of its own ([below](#permissions)).

## Set up SES

Do these in the AWS console, in the region the mailer names. AWS's guides cover the first two and the last; SES's own docs leave the EventBridge steps between them unclear, so they're here in full.

1. **The identity.** [Verify your sending domain](https://docs.aws.amazon.com/ses/latest/dg/creating-identities.html#verify-domain-procedure) itself (`news.example.com`) with Easy DKIM, even when a parent domain that covers it is verified already, and give it a [custom MAIL FROM domain](https://docs.aws.amazon.com/ses/latest/dg/mail-from.html) under it (`mail.news.example.com`) so SPF passes for your domain too. A parent identity signs with the parent's DKIM keys, so your campaigns would share its reputation; its settings (feedback forwarding below, a default configuration set) are your other mail's too; and the package reads Gmail's numbers and SES's daily counts for the exact sending domain. If you keep the parent identity anyway, name it in the [policy](#permissions) in place of the sending domain. Add a DMARC record of your own ([Installation](./installation.md#choose-the-sending-domain)); SES doesn't.
2. **The configuration set.** [Create one](https://docs.aws.amazon.com/ses/latest/dg/using-configuration-sets.html) for campaigns alone and name it in the mailer's `ConfigurationSetName`, even when the identity has a default set. Add an [event destination](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-eventbridge.html) of type Amazon EventBridge with these event types: Sends, Deliveries, Hard bounces, Complaints, Rejects and Delivery delays. Leave Opens and Clicks out; the driver drops any that arrive. Without a configuration set, no event ever reaches the package, and nothing warns you.
3. **The connection.** In EventBridge, create a [connection](https://docs.aws.amazon.com/eventbridge/latest/userguide/eb-api-destinations.html) with API key authorization: the key name `X-Campaigns-Webhook-Key` and the value of `CAMPAIGNS_SES_WEBHOOK_SECRET`. EventBridge sends them as a header on every request.
4. **The API destination.** Point it at `https://your-app.example.com/campaigns/webhooks/ses` with method POST, using that connection.
5. **The rule.** On the default event bus, add a rule with the API destination as its target, matching only this configuration set's sending events, so another app's mail on the same account never reaches yours. The target needs an execution role that allows `events:InvokeApiDestination` on the API destination ([AWS's guide to EventBridge roles](https://docs.aws.amazon.com/eventbridge/latest/userguide/eb-events-iam-roles.html)). Send the event as it is, with no input transformer:

   ```json
   {
     "source": ["aws.ses"],
     "detail-type": ["Email Sent", "Email Delivered", "Email Bounced", "Email Complaint Received", "Email Rejected", "Email Delivery Delayed"],
     "detail": {"mail": {"tags": {"ses:configuration-set": ["your-configuration-set"]}}}
   }
   ```
6. **Feedback forwarding.** On the identity's Notifications tab, turn Email feedback forwarding off, or call `PutEmailIdentityFeedbackAttributes` with `EmailForwardingEnabled` set to `false`. SES turns it on for every identity and emails each bounce and spam report to the address the email came from. A forwarded report can carry the email it's about (a spam report follows RFC 5965), so opening the report in a mail app can load that email's open pixel, or a click in it follow a tracked link, and record an open or a click for the person who complained. The package already reads bounces and complaints from the events. SES keeps forwarding, whatever the switch says, for an email whose bounces and complaints reach you no other way: its guide counts an SNS topic or a Firehose stream as another way, its API reference any event destination. If reports keep arriving with forwarding off, give the identity SNS notifications for bounces and complaints too.
7. **Production access.** A new account is in the sandbox in each region: 200 emails a day, one a second, and only to addresses you verified. [Ask AWS for production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html) in that region before your first campaign.

EventBridge posts from AWS's servers, so a CDN or firewall in front of the app must let `POST /campaigns/webhooks/ses` through without a browser or bot check.

After your first test email, `get_test_sends` shows its `delivered_at` once the Delivery event reaches the app ([Installation](./installation.md#turn-sending-on-and-send-a-test)). If it never comes, the rule's metrics in CloudWatch say whether it matched the event and whether calling the API destination failed; the app answers 406 when the connection's key doesn't match `CAMPAIGNS_SES_WEBHOOK_SECRET`.

To try it without real people, send to SES's mailbox simulator: `success@simulator.amazonses.com` gets a delivery, `bounce@simulator.amazonses.com` a hard bounce and `complaint@simulator.amazonses.com` a spam report. `suppressionlist@simulator.amazonses.com` stands for an address on SES's global suppression list, though its bounce comes as an ordinary permanent 5.1.1 (subtype General, not Suppressed), so it reads as any hard bounce. Send them in a campaign to see what they do: a test email's events are stored and change nothing. In a campaign, a bounce blocks the address and counts toward the stop-loss, although SES leaves simulator emails out of the account's own bounce and complaint rates. The simulator works in the sandbox, and SES bills those emails like any other.

### Permissions

The credentials in the mailer need:

- `ses:SendEmail` and `ses:SendRawEmail` to send, since Laravel's transport sends each email as raw content;
- `ses:ApplyTrackingConfigurationOverrides` for the switch that turns SES's own tracking off on each email;
- `ses:ListSuppressedDestinations` and `ses:GetSuppressedDestination` for the suppressions import and `unblock_contact`;
- `ses:GetAccount` for the account's sending quota, which the overview and the start, resume and speed-up answers show;
- `ses:BatchGetMetricData` when VDM is on.

When SES denies a send, it answers 403: the email waits, and every campaign on the sending domain pauses with a reason that names the three send actions. A policy for one region, with your region, account id, sending domain and configuration set in place of the examples:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ses:SendEmail", "ses:SendRawEmail"],
      "Resource": [
        "arn:aws:ses:us-east-1:123456789012:identity/news.example.com",
        "arn:aws:ses:us-east-1:123456789012:configuration-set/your-configuration-set"
      ]
    },
    {
      "Effect": "Allow",
      "Action": ["ses:ApplyTrackingConfigurationOverrides", "ses:GetAccount", "ses:ListSuppressedDestinations", "ses:GetSuppressedDestination"],
      "Resource": "*",
      "Condition": {"StringEquals": {"aws:RequestedRegion": "us-east-1"}}
    }
  ]
}
```

The tracking switch has no resource to name, and the suppression list and the quota cover the whole account, so the second statement takes `*`, held to the region. With VDM on, add `ses:BatchGetMetricData` to it.

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
| a suspended or paused account, an unverified MAIL FROM domain, `MessageRejected` (an unverified From address, or content SES refuses), `LimitExceededException`, a configuration set it doesn't have, or credentials it refuses (401, 403, 404) | the email waits and every campaign on the sending domain pauses until someone acts; after a 401 or 403 the pause reason names the [permissions](#permissions) a send needs |
| `BadRequestException` | the email fails as `rejected`; 10 in a row pause the campaign |
| no answer, a 504, or anything else | the email is `unknown` and never resent |

SES keeps no logs the package can read. An event EventBridge couldn't deliver is lost, and an email whose send ended unclear stays `unknown` unless its Send or Delivery event arrives. EventBridge retries a failed delivery for up to 24 hours and waits at most 5 seconds for each answer, so keep the webhook reachable; a dead-letter queue on the rule's target shows you what was lost, though the package can't read it.

SES has no unsubscribe of its own here: the package's page and the one-click header handle it, as with any driver.

## Suppressions and daily counts

SES keeps one suppression list for the whole account in each region, holding bounces and complaints, whatever domain sent the mail. `campaigns:import-provider-suppressions` reads all of it, whichever domain you pass, so one run covers your transactional mail's bounces too when it goes through the same account and region. SES compares addresses on that list as they were added, so `unblock_contact` can miss one added in other letter cases.

It works the other way too: a campaign's hard bounce or spam report puts the address on the list, and SES then holds back your other mail to it from the same account and region, receipts included. Mail sent with a configuration set that has [suppression settings of its own](https://docs.aws.amazon.com/ses/latest/dg/sending-email-suppression-list-config-level.html) uses those and ignores the account's.

`get_overview`'s `domain.provider` shows SES's sends, hard bounces and complaints by day only with [Virtual Deliverability Manager](https://docs.aws.amazon.com/ses/latest/dg/vdm.html), a paid add-on and an optional extra. Turn it on in SES, then set:

```dotenv
CAMPAIGNS_SES_VDM=true
```

Without it, the overview notes that the counts need VDM. SES counts no forwarded mail and no unsubscribes, so those stay null.

The package asks VDM for the sending domain's own identity. If you send from `news.example.com` under the identity `example.com`, VDM may have nothing for `news.example.com`: when SES answers with no counts at all, `domain.provider` stays null and the overview notes it, rather than showing 30 days of zeros. Verify the sending domain as an identity of its own to read them. A domain that sent nothing through SES in the last 30 days gets the same note.

## The sending quota

SES limits how many emails the account sends in any 24 hours and how many a second, per region. `get_overview` shows them as `usage`: what the account sent in the last 24 hours, its quota, what's left and the rate. `start_campaign`, `resume_campaign` and `speed_up_campaign` show the same in their answer, and their `warnings` say when what the campaign may send in the next 24 hours (its emails still to send, or what its pace allows in that time when that's lower) passes what the quota leaves. SES holds back what passes it, so the campaign's sending slows until the 24 hours allow more.

The usage also notes an account still in SES's sandbox (it sends only to verified addresses, so a campaign to anyone else fails), sending paused for the account, and an enforcement status other than healthy.
