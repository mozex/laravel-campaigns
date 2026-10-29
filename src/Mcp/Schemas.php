<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\ImportChange;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Reports\ListSummary;
use Mozex\Campaigns\Reports\SubscriberSummary;

/**
 * The shapes several tools answer with, declared once.
 */
class Schemas
{
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
