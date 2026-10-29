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
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Reports\AssetSummary;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Reports\ListSummary;
use Mozex\Campaigns\Reports\SubscriberSummary;

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
     * A list with its counts and details (ListSummary::rows() with details).
     */
    public static function list(JsonSchema $schema): Type
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
            'double_opt_in' => $schema->boolean()->required()->description('Whether someone added waits to confirm by email before they are mailed.'),
            'default_basis' => $schema->string()->enum(array_column(ConsentBasis::cases(), 'value'))->required(),
            ...$statusCounts,
            'mailable' => $schema->integer()->required()->description('Subscribed people who aren\'t opted out, blocked or sunset.'),
            'tiers' => $schema->object($tierCounts)->required()->description('Subscribed people by engagement tier.'),
            'created_at' => $schema->string()->nullable()->required(),
            'archived_at' => $schema->string()->nullable()->required(),
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
            'blocked_reason' => $schema->string()->enum(array_column(BlockReason::cases(), 'value'))->nullable(),
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
     * Where a revision's pre-send checks stand (CheckSummary::status()).
     */
    public static function checkStatus(JsonSchema $schema): Type
    {
        return $schema->object([
            'status' => $schema->string()->enum(['none', 'queued', 'done', 'failed'])->required()->description('none: never run; failed: the run itself died (message says why).'),
            'queued_at' => $schema->string()->nullable()->required(),
            'finished_at' => $schema->string()->nullable()->required(),
            'passed' => $schema->integer()->nullable()->required(),
            'failed' => $schema->integer()->nullable()->required(),
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
     * The state an agent's change left a subscriber in.
     */
    public static function subscriberState(JsonSchema $schema): Type
    {
        return $schema->object([
            'email' => $schema->string()->required(),
            'mailable' => $schema->boolean()->required(),
            'opted_out_at' => $schema->string()->nullable()->required(),
            'blocked_at' => $schema->string()->nullable()->required(),
            'blocked_reason' => $schema->string()->enum(array_column(BlockReason::cases(), 'value'))->nullable()->required(),
        ]);
    }
}
