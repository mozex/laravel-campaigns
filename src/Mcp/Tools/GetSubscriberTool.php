<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\SubscriberSummary;

#[Name('get_subscriber')]
#[Title('Look up a subscriber')]
#[Description('One address whole: details, engagement, whether and why it can\'t be mailed, every list with how it joined, custom fields, and its consent history newest first (what changed, on which list, on what basis, from where). The IP address and browser a change came from stay on the server.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetSubscriberTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['email' => $schema->string()->required()];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            ...Schemas::subscriberBasics($schema, required: false),
            'timezone' => $schema->string()->nullable(),
            'soft_bounces' => $schema->integer()->description('Temporary failures in a row; three block the address.'),
            'last_opened_at' => $schema->string()->nullable(),
            'last_clicked_at' => $schema->string()->nullable(),
            'last_active_at' => $schema->string()->nullable(),
            'confirmation_sent_at' => $schema->string()->nullable()->description('The last double opt-in email.'),
            'custom_fields' => $schema->object()->description('Text values cut to '.SubscriberSummary::FIELD_LENGTH.' characters.'),
            'lists' => $schema->array()->items($schema->object([
                'key' => $schema->string()->nullable()->required(),
                'status' => $schema->string()->enum(array_column(SubscriptionStatus::cases(), 'value'))->required(),
                'basis' => $schema->string()->enum(array_column(ConsentBasis::cases(), 'value'))->required(),
                'source' => $schema->string()->required(),
                'subscribed_at' => $schema->string()->nullable()->required(),
                'confirmed_at' => $schema->string()->nullable()->required(),
            ]))->description('At most '.SubscriberSummary::DETAIL_LISTS.'; lists_total says how many.'),
            'lists_total' => $schema->integer(),
            'ledger' => $schema->array()->items($schema->object([
                'action' => $schema->string()->enum(array_column(ConsentAction::cases(), 'value'))->required(),
                'list' => $schema->string()->nullable()->required(),
                'basis' => $schema->string()->nullable()->required(),
                'source' => $schema->string()->nullable()->required(),
                'notice_version' => $schema->string()->nullable()->required(),
                'occurred_at' => $schema->string()->nullable()->required(),
                'meta' => $schema->string()->nullable()->required()->description('As JSON, cut to '.SubscriberSummary::META_LENGTH.' characters.'),
            ]))->description('The latest '.SubscriberSummary::LEDGER_ENTRIES.', newest first; ledger_total says how many.'),
            'ledger_total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate(['email' => ['required', 'string']]);
        $detail = SubscriberSummary::detail(static::subscriber($input['email']));

        // An empty map would go out as a JSON array, against the schema.
        $detail['custom_fields'] = (object) $detail['custom_fields'];

        return Response::structured($detail);
    }
}
