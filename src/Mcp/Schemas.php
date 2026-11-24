<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\ImportChange;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Reports\AssetSummary;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Reports\ListActivity;
use Mozex\Campaigns\Reports\ListSummary;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Reports\SubscriberSummary;
use Mozex\Campaigns\Sending\Usage;

/**
 * The shapes several tools answer with, declared once.
 */
class Schemas
{
    /**
     * The built-in subscriber filters, as list_subscribers, a saved audience
     * and a campaign's audience take them.
     */
    public const FILTERS = 'lists (keys; list_status subscribed by default, or pending or unsubscribed), locales, countries, tiers (engaged, active, silent), opted_out, blocked, sunset (true or false), custom_fields (name to value; null matches missing), dates (a list of {field, after, before}, field one of created, last_opened, last_clicked, last_active, first_mailed, opted_out, blocked), campaigns (a list of {campaign key, outcome}, outcome one of received, not_received, opened, not_opened, clicked, not_clicked). Filters combine with AND, a filter\'s values with OR';

    /**
     * A campaign's audience within its lists, as update_campaign and
     * preview_audience take it.
     */
    public const AUDIENCE = 'Who it goes to within its lists; every part given narrows (AND), and with none it goes to everyone subscribed on them: audience ({"name": a named audience from list_audiences, "parameters": {...}}), saved_audience (a key), filters (the built-in filters: '.self::FILTERS.'), subscribers (ids) and emails (addresses), one set of people, and records ({"<dynamic list key>": [app record ids]}). Each names at most 10,000. Opted-out, blocked and sunset people are never mailed whatever it says.';

    /**
     * What a list's is_default says, wherever a list is answered.
     */
    public const DEFAULT_LIST = 'Whether it is a default list: a campaign created without lists of its own goes to the default lists.';

    /**
     * A campaign's pace settings, as the drafting and pace tools take them.
     */
    public const PACE = 'Any of first_day, daily_growth, daily_cap, per_minute, sending_hours, timezone: first_day (sends on day 1), daily_growth (each day\'s growth, a percentage from 0 to 1000), daily_cap (most a day; null for none), per_minute, sending_hours (["09:00", "17:00"]; null for any time), timezone (like Europe/Paris). Left out, a value follows the app\'s config.';

    /**
     * A list with its counts and details (ListSummary::rows() with details),
     * and with activity how it fills and when it was last used.
     */
    public static function list(JsonSchema $schema, bool $activity = false): Type
    {
        $statusCounts = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $statusCounts[$status->value] = $schema->integer()->required();
        }

        $tierCounts = [];

        foreach (EngagementTier::cases() as $tier) {
            $tierCounts[$tier->value] = $schema->integer()->required();
        }

        return $schema->object([
            'key' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required()->description('Cut to '.ListSummary::DESCRIPTION_LENGTH.' characters.'),
            'kind' => $schema->string()->enum(array_column(ListKind::cases(), 'value'))->required()->description('dynamic: kept in step with the app\'s records, never changed by hand; static: contacts added by agents, imports or the app.'),
            'is_default' => $schema->boolean()->required()->description(static::DEFAULT_LIST),
            'double_opt_in' => $schema->boolean()->required()->description('Whether someone added waits to confirm by email before they are mailed.'),
            'default_basis' => $schema->string()->enum(array_column(ConsentBasis::cases(), 'value'))->required(),
            ...$statusCounts,
            'mailable' => $schema->integer()->required()->description('Subscribed people who aren\'t opted out, blocked or sunset.'),
            'tiers' => $schema->object($tierCounts)->required()->description('Subscribed people by engagement tier.'),
            'created_at' => $schema->string()->nullable()->required(),
            'archived_at' => $schema->string()->nullable()->required(),
            ...$activity ? [
                'records' => $schema->string()->nullable()->required()->description('A dynamic list: the type of the app\'s records that keep it filled. Null for a static list, which fills only through joins, and for a dynamic list whose source the app\'s config no longer names or can\'t load, so nothing keeps it filled now.'),
                'recent_joins' => $schema->object([
                    'total' => $schema->integer()->required(),
                    'by_source' => $schema->array()->items($schema->object([
                        'source' => $schema->string()->nullable()->required()->description('Cut to '.ListActivity::SOURCE_LENGTH.' characters.'),
                        'count' => $schema->integer()->required(),
                    ]))->required()->description('The '.ListActivity::SOURCES.' largest sources, the largest first.'),
                    'other' => $schema->integer()->required()->description('Joins from the other sources.'),
                ])->required()->description('Who joined in the last '.ListActivity::JOIN_DAYS.' days, by where they came from: the source the app\'s code or a contacts import named for the change (add_contacts writes agent). On a double opt-in list it includes people still waiting to confirm. A record coming back to a dynamic list isn\'t a join.'),
                'last_joined_at' => $schema->string()->nullable()->required(),
                'last_campaign' => $schema->object([
                    'key' => $schema->string()->required(),
                    'started_at' => $schema->string()->required(),
                ])->nullable()->required()->description('The campaign that last started with this list among its lists; null when none has.'),
            ] : [],
        ]);
    }

    /**
     * What an import did, or would do (ImportResult::toArray(), its
     * problems cut to the first few).
     *
     * @return array<string, Type>
     */
    public static function importResult(JsonSchema $schema): array
    {
        $counts = [];

        foreach (ImportChange::cases() as $change) {
            $counts[$change->value] = $schema->integer()->required()->description($change->label());
        }

        return [
            'dry_run' => $schema->boolean()->description('True when nothing was saved.'),
            'rows' => $schema->integer()->description('The contacts read.'),
            'counts' => $schema->object($counts)->description('How many contacts made each change; one can make several.'),
            'never_mailed' => $schema->integer()->description('Contacts that end opted out or blocked, so never mailed.'),
            'problems' => $schema->array()->items($schema->string())->description('The contacts that couldn\'t be added, by row number (the first contact is row 1), and why.'),
            'problems_total' => $schema->integer(),
        ];
    }

    /**
     * The fields a subscriber's row and its whole share; required inside a
     * row, never at an answer's top level, where a refusal carries none.
     *
     * @return array<string, Type>
     */
    public static function subscriberBasics(JsonSchema $schema, bool $required = true): array
    {
        return array_map(fn (Type $type): Type => $type->required($required), [
            'email' => $schema->string(),
            'name' => $schema->string()->nullable(),
            'first_name' => $schema->string()->nullable(),
            'locale' => $schema->string()->nullable(),
            'country' => $schema->string()->nullable(),
            'linked' => $schema->boolean()->description('Whether an app record (an account) stands behind the address.'),
            'tier' => $schema->string()->enum(array_column(EngagementTier::cases(), 'value')),
            'sunset' => $schema->boolean()->description('Silent so long it is no longer mailed.'),
            'mailable' => $schema->boolean()->description('Not opted out, blocked or sunset.'),
            'opted_out_at' => $schema->string()->nullable(),
            'blocked_at' => $schema->string()->nullable(),
            'blocked_reason' => $schema->string()->enum([...array_column(BlockReason::cases(), 'value'), null])->nullable(),
            'last_engaged_at' => $schema->string()->nullable()->description('The latest open, click or app activity.'),
            'first_mailed_at' => $schema->string()->nullable(),
            'created_at' => $schema->string()->nullable(),
        ]);
    }

    /**
     * A subscriber in a page of them (SubscriberSummary::rows()).
     */
    public static function subscriberRow(JsonSchema $schema): Type
    {
        return $schema->object([
            ...static::subscriberBasics($schema),
            'lists' => $schema->array()->items($schema->object([
                'key' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum(array_column(SubscriptionStatus::cases(), 'value'))->required(),
            ]))->required()->description('At most '.SubscriberSummary::ROW_LISTS.'; lists_total says how many.'),
            'lists_total' => $schema->integer()->required(),
            'custom_fields_count' => $schema->integer()->required(),
        ]);
    }

    /**
     * An image in the asset library (AssetSummary::row()).
     */
    public static function asset(JsonSchema $schema): Type
    {
        return $schema->object([
            'id' => $schema->string()->required(),
            'name' => $schema->string()->required()->description('Cut to '.AssetSummary::NAME_LENGTH.' characters.'),
            'url' => $schema->string()->required()->description('Its public https address, for an email\'s <img src>.'),
            'mime' => $schema->string()->required(),
            'size' => $schema->integer()->required()->description('Bytes.'),
            'width' => $schema->integer()->nullable()->required(),
            'height' => $schema->integer()->nullable()->required(),
            'author' => $schema->string()->nullable()->required()->description('Who added it.'),
            'created_at' => $schema->string()->nullable()->required(),
        ]);
    }

    /**
     * One of the package's starter templates (TemplateSummary::starters()).
     */
    public static function starter(JsonSchema $schema): Type
    {
        return $schema->object([
            'key' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->required(),
            'locales' => $schema->array()->items($schema->string())->required(),
        ]);
    }

    /**
     * One of the app's templates (TemplateSummary::row()).
     */
    public static function template(JsonSchema $schema): Type
    {
        return $schema->object([
            'key' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
            'is_default' => $schema->boolean()->required()->description('One of the app\'s own defaults to start from.'),
            'current_revision' => $schema->integer()->nullable()->required()->description('The number of the email\'s current revision.'),
            'locales' => $schema->array()->items($schema->string())->required()->description('The languages its current email is in.'),
            'archived_at' => $schema->string()->nullable()->required(),
            'created_at' => $schema->string()->nullable()->required(),
            'updated_at' => $schema->string()->nullable()->required(),
        ]);
    }

    /**
     * Gmail's numbers for the sending domain (GmailReading::for()).
     */
    public static function gmail(JsonSchema $schema): Type
    {
        $rate = fn (string $description): Type => $schema->number()->nullable()->required()->description($description);

        $verdict = $schema->object([
            'status' => $schema->string()->nullable()->required()->description('COMPLIANT or NEEDS_WORK.'),
            'reason' => $schema->string()->nullable()->required()->description('Google\'s reason code, when it gives one.'),
        ])->nullable()->required();

        $compliance = $schema->object([
            'domain' => $schema->string()->nullable()->required(),
            'requirements' => $schema->object()->required()->description('Each of Google\'s bulk-sender requirements (SPF_AND_DKIM, DMARC_POLICY, DMARC_ALIGNMENT, ENCRYPTION, DNS_RECORDS, USER_REPORTED_SPAM_RATE, ...) to COMPLIANT or NEEDS_WORK.'),
            'one_click_unsubscribe' => $verdict,
            'honor_unsubscribe' => $verdict,
            'deliverability' => $verdict,
        ])->nullable()->required();

        return $schema->object([
            'read' => $schema->boolean()->required()->description('Whether Google answered; false when no key is set or the read failed (note says which).'),
            'read_at' => $schema->string()->nullable()->required()->description('When Google answered: a reading is kept for an hour.'),
            'spam_rate' => $rate('The latest day\'s known user-reported spam rate, in percent (0.1 is 0.1%); null when no day of the window has one: unknown, never clean.'),
            'spam_rate_date' => $schema->string()->nullable()->required()->description('The UTC date of spam_rate.'),
            'warning' => $schema->string()->nullable()->required()->description('Set when spam_rate is at or above 0.1%, the rate Google asks bulk senders to stay under, or at or above 0.3%, which Google says a bulk sender must never reach.'),
            'note' => $schema->string()->nullable()->required()->description('Why Gmail wasn\'t read, or that it reported no spam rate in the window.'),
            'from' => $schema->string()->nullable()->required()->description('The first UTC date read.'),
            'to' => $schema->string()->nullable()->required()->description('The last UTC date read: yesterday, since Gmail reports about a day late.'),
            'days' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required(),
                'spam_rate' => $rate('Percent of the day\'s mail to Gmail inboxes that people reported as spam.'),
                'dkim_success_rate' => $rate('Percent of the day\'s mail that passed DKIM.'),
                'spf_success_rate' => $rate('Percent that passed SPF.'),
                'dmarc_success_rate' => $rate('Percent that passed DMARC.'),
            ]))->required()->description('Every day read, oldest first. A rate is null when unknown: Gmail reports a day only when the domain sent it enough mail, and a day it filled with zeros counts as unknown.'),
            'compliance' => $schema->object([
                'registrable_domain' => $compliance,
                'subdomain' => $compliance,
            ])->nullable()->required()->description('Google\'s bulk-sender checks for the registrable domain and, when Google has them, the sending subdomain itself.'),
        ]);
    }

    /**
     * What the account behind the driver sent against its allowance
     * (ProviderUsage::read()).
     */
    public static function usage(JsonSchema $schema): Type
    {
        return $schema->object([
            'read' => $schema->boolean()->required()->description('Whether the provider answered; false when the driver reads no usage or the read failed (note says which).'),
            'read_at' => $schema->string()->nullable()->required()->description('When the provider answered: a reading is kept for an hour.'),
            'period' => $schema->string()->enum([Usage::MONTH, Usage::DAY, null])->nullable()->required()->description('month: the calendar month (UTC) so far, for a plan with a monthly volume; last_24_hours: a rolling quota.'),
            'from' => $schema->string()->nullable()->required(),
            'to' => $schema->string()->nullable()->required(),
            'sent' => $schema->integer()->nullable()->required()->description('What the provider counted in the period for the whole account, every app and domain on it (with a plan, what it bills, forwarded mail included).'),
            'allowance' => $schema->integer()->nullable()->required()->description('What the plan includes or the quota allows in the period; null when unlimited or not known (note says which).'),
            'left' => $schema->integer()->nullable()->required(),
            'past_allowance' => $schema->string()->enum([...ProviderUsage::PAST_ALLOWANCE, null])->nullable()->required()->description('What the provider does with mail past the allowance: billed (as overage) or held_back (sending slows until the period allows more).'),
            'per_second' => $schema->number()->nullable()->required()->description('The most emails the provider takes a second, when it sets a limit.'),
            'note' => $schema->string()->nullable()->required()->description('Why the usage wasn\'t read, what the count leaves out, or what the account\'s state means for sending.'),
        ]);
    }

    /**
     * What the start, resume and speed-up answers read after the change:
     * Gmail's numbers for the campaign's sending domain and the provider's
     * usage.
     */
    public static function readings(JsonSchema $schema): Type
    {
        return $schema->object([
            'gmail' => static::gmail($schema)->required()->description('Gmail\'s numbers for the campaign\'s sending domain, as get_overview\'s domain.gmail.'),
            'usage' => static::usage($schema)->required()->description('What the account sent against its plan or quota, as get_overview\'s usage.'),
        ])->nullable()->description('Null when they couldn\'t be read; warnings says so.');
    }

    /**
     * Where a revision's pre-send checks stand (CheckSummary::status()).
     */
    public static function checkStatus(JsonSchema $schema): Type
    {
        return $schema->object([
            'status' => $schema->string()->enum(['none', 'queued', 'done', 'failed'])->required()->description('none: never run; failed: the run itself died (message says why).'),
            'queued_at' => $schema->string()->nullable()->required(),
            'finished_at' => $schema->string()->nullable()->required(),
            'passed' => $schema->integer()->nullable()->required()->description('How many checks passed; null until a run is done.'),
            'failed' => $schema->integer()->nullable()->required()->description('How many checks failed; the rest were skipped.'),
            'message' => $schema->string()->nullable()->required(),
        ]);
    }

    /**
     * The fields of a revision's row in its history (RevisionSummary::row()).
     *
     * @return array<string, Type>
     */
    public static function revisionRow(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->required(),
            'note' => $schema->string()->nullable()->required(),
            'author' => $schema->string()->nullable()->required(),
            'created_at' => $schema->string()->nullable()->required(),
            'locales' => $schema->array()->items($schema->string())->required(),
            'frozen_from' => $schema->integer()->nullable()->required()->description('For the copy a campaign\'s start made with UTM tags added: the revision it copied.'),
            'checks' => static::checkStatus($schema)->required(),
        ];
    }

    /**
     * A revision whole, with its download links (RevisionSummary::detail()).
     */
    public static function revision(JsonSchema $schema): Type
    {
        return $schema->object([
            ...static::revisionRow($schema),
            'versions' => $schema->array()->items($schema->object([
                'locale' => $schema->string()->required(),
                'subject' => $schema->string()->required(),
                'preheader' => $schema->string()->nullable()->required(),
                'html_bytes' => $schema->integer()->required(),
                'text' => $schema->string()->enum(['written', 'generated'])->required()->description('generated: made from the HTML, and made again on every upload.'),
            ]))->required()->description('One per language, the default first.'),
            'downloads' => $schema->object([
                'zip' => $schema->string()->required()->description('Every language\'s files, laid out as an upload takes them: download, edit, and send the zip back.'),
                'html' => $schema->string()->required()->description('The default language\'s HTML alone.'),
                'expires_at' => $schema->string()->required(),
            ])->required(),
        ]);
    }

    /**
     * The fields of a campaign's row in a list (CampaignSummary::row()).
     *
     * @return array<string, Type>
     */
    public static function campaignRow(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->required(),
            'key' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'status' => $schema->string()->enum(array_column(CampaignStatus::cases(), 'value'))->required(),
            'from_email' => $schema->string()->required(),
            'lists' => $schema->array()->items($schema->string())->required()->description('Its target lists\' keys: the first '.CampaignSummary::ROW_LISTS.' in a list of campaigns, '.CampaignSummary::DETAIL_LISTS.' for one campaign.'),
            'lists_total' => $schema->integer()->required(),
            'recipient_count' => $schema->integer()->nullable()->required()->description('How many it was frozen for, once started or scheduled.'),
            'created_at' => $schema->string()->nullable()->required(),
            'scheduled_at' => $schema->string()->nullable()->required(),
            'started_at' => $schema->string()->nullable()->required(),
            'completed_at' => $schema->string()->nullable()->required(),
            'canceled_at' => $schema->string()->nullable()->required(),
        ];
    }

    /**
     * One campaign whole (CampaignSummary::detail()).
     */
    public static function campaign(JsonSchema $schema): Type
    {
        return $schema->object([
            ...static::campaignRow($schema),
            'from_name' => $schema->string()->nullable()->required(),
            'reply_to' => $schema->string()->nullable()->required(),
            'track_opens' => $schema->boolean()->required(),
            'track_clicks' => $schema->boolean()->required(),
            'utm' => $schema->object()->required()->description('The UTM tags its start adds to links to the app\'s own sites; empty for none.'),
            'pace' => $schema->object()->required()->description('The pace settings given; the rest follow the app\'s config.'),
            'thresholds' => $schema->object()->required()->description('Its own stop-loss limits; the rest follow the app\'s config.'),
            'audience' => $schema->object([
                'audience' => $schema->string()->nullable()->required()->description('A named audience the app defines.'),
                'parameters' => $schema->string()->nullable()->required()->description('Its parameters as JSON, cut to '.CampaignSummary::AUDIENCE_TEXT_LENGTH.' characters.'),
                'saved_audience' => $schema->string()->nullable()->required(),
                'filters' => $schema->string()->nullable()->required()->description('As JSON, cut to '.CampaignSummary::AUDIENCE_TEXT_LENGTH.' characters.'),
                'subscribers' => $schema->integer()->nullable()->required()->description('How many people it names by id or address; 0 names nobody, null none were named.'),
                'records' => $schema->object()->required()->description('How many app records it names, by dynamic list.'),
                'unmatched_emails' => $schema->integer()->required()->description('Addresses given that matched no subscriber.'),
            ])->required()->description('Who it goes to within its lists; every part given narrows.'),
            'template' => $schema->string()->nullable()->required()->description('The template it was made from.'),
            'duplicated_from' => $schema->string()->nullable()->required(),
            'start_error' => $schema->string()->nullable()->required()->description('Why its last start was handed back to a draft.'),
            'paused_at' => $schema->string()->nullable()->required(),
            'pause_reason' => $schema->string()->nullable()->required(),
            'revisions' => $schema->integer()->required()->description('How many revisions its email has; list_revisions lists them.'),
            'revision' => static::revision($schema)->nullable()->required()->description('Its current email.'),
        ]);
    }

    /**
     * Where a campaign's sending stands (CampaignProgress::of()).
     */
    public static function progress(JsonSchema $schema): Type
    {
        $statuses = [];

        foreach (MessageStatus::cases() as $status) {
            $statuses[$status->value] = $schema->integer()->required();
        }

        return $schema->object([
            'uuid' => $schema->string()->required(),
            'key' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'status' => $schema->string()->enum(array_column(CampaignStatus::cases(), 'value'))->required()->description('starting while a start is being frozen: read it again until it is sending, scheduled, or a draft with start_error.'),
            'recipient_count' => $schema->integer()->nullable()->required()->description('How many it was frozen for.'),
            'start_error' => $schema->string()->nullable()->required()->description('Why its last start was handed back to a draft.'),
            'scheduled_at' => $schema->string()->nullable()->required(),
            'started_at' => $schema->string()->nullable()->required(),
            'paused_at' => $schema->string()->nullable()->required(),
            'pause_reason' => $schema->string()->nullable()->required()->description('An automatic pause names the numbers and the limit (its key) that stopped it.'),
            'completed_at' => $schema->string()->nullable()->required(),
            'canceled_at' => $schema->string()->nullable()->required(),
            'messages' => $schema->object($statuses)->required()->description('Its messages by status: pending (not handed out yet), queued (waiting on the queue), sending, sent (the provider took it; where an email ends when the driver reads nothing back), delivered, failed, skipped (not mailable by the time its turn came), unknown (a send that ended unclear and the provider\'s logs could no longer settle, or can\'t, through a driver without logs).'),
            'sends' => $schema->integer()->required()->description('Emails the provider took.'),
            'failures' => $schema->object()->required()->description('Failed messages by kind: hard_bounce (blocks the address; also a refusal naming a bad mailbox as it was sent), soft_bounce (blocks it after 3 in a row), sender_blocked (the receiver refused the sender, nothing against the address), suppressed_bounce, suppressed_complaint and suppressed_unsubscribe (the provider held the address from before and sent nothing), other, rejected (the service refused it for good as it was sent, not for its address; 10 in a row pause the campaign), and not_accepted (the provider\'s logs held nothing for it an hour after an unclear send; never resent).'),
            'queue' => $schema->object([
                'pump_last_run' => $schema->string()->nullable()->required()->description('When the scheduler last ran the pump (every minute).'),
                'pending' => $schema->integer()->required(),
                'queued' => $schema->integer()->required(),
                'stalled' => $schema->integer()->required()->description('Queued for more than five minutes: no worker is taking them, and the campaign hands out nothing more until they go.'),
                'oldest_queued_at' => $schema->string()->nullable()->required(),
                'jobs_waiting' => $schema->integer()->nullable()->required()->description('Jobs on the campaigns queue, all campaigns\' and the package\'s other work; null when the queue can\'t say.'),
            ])->required(),
            'stalled' => $schema->boolean()->required(),
            'pace' => $schema->object([
                'first_day' => $schema->integer()->required(),
                'daily_growth' => $schema->number()->required()->description('Percent more each day.'),
                'daily_cap' => $schema->integer()->nullable()->required(),
                'per_minute' => $schema->integer()->required(),
                'sending_hours' => $schema->array()->items($schema->string())->nullable()->required()->description('["09:00", "17:00"] in timezone; null sends all day.'),
                'timezone' => $schema->string()->required(),
                'rate_halved_until' => $schema->string()->nullable()->required()->description('The per-minute rate is halved until then, after the provider took nothing (a rate limit or an outage) or a send ended without a clear answer.'),
                'day' => $schema->integer()->nullable()->required()->description('The day of the pace it is on, counted in 24 hours from its start (or from a resume after a pause of more than a day).'),
                'day_volume' => $schema->integer()->nullable()->required()->description('How many that day may send.'),
                'in_sending_hours' => $schema->boolean()->required(),
                'allowance_now' => $schema->integer()->nullable()->required()->description('How many it may hand out this minute, while sending: the least of the per-minute rate, the day\'s volume and the domain\'s daily cap, each less what was used; 0 outside its sending hours, while sending is switched off, or while its queue is stalled.'),
            ])->required()->description('The pace in effect: its own settings over the app\'s config.'),
            'limits' => $schema->object()->required()->description('The stop-loss limits in effect: its own thresholds over the app\'s config.'),
        ]);
    }

    /**
     * The state an agent's change left a subscriber in.
     */
    public static function subscriberState(JsonSchema $schema): Type
    {
        return $schema->object([
            'email' => $schema->string()->required(),
            'mailable' => $schema->boolean()->required(),
            'opted_out_at' => $schema->string()->nullable()->required(),
            'blocked_at' => $schema->string()->nullable()->required(),
            'blocked_reason' => $schema->string()->enum([...array_column(BlockReason::cases(), 'value'), null])->nullable()->required(),
        ]);
    }
}
