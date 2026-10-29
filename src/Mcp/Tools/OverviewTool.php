<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Reports\Overview;

#[Name('get_overview')]
#[Title('Get overview')]
#[Description('Start here. How sending is set up and what is switched off, the addresses test emails may go to, every list with its counts (by status, how many may be mailed, and by engagement tier), the campaigns starting, scheduled, sending or paused, and the placeholders an email may hold with this app\'s values. At most 25 lists and 25 campaigns; lists_total and campaigns_total say how many there are.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class OverviewTool extends CampaignsTool
{
    protected function output(JsonSchema $schema): array
    {
        $statusCounts = [];

        foreach (SubscriptionStatus::cases() as $status) {
            $statusCounts[$status->value] = $schema->integer()->required();
        }

        $tierCounts = [];

        foreach (EngagementTier::cases() as $tier) {
            $tierCounts[$tier->value] = $schema->integer()->required();
        }

        return [
            'app' => $schema->string()->description('What the emails and pages call the app.'),
            'package_version' => $schema->string()->nullable(),
            'sending' => $schema->object([
                'enabled' => $schema->boolean()->required()->description('Whether campaigns may start and test emails go out.'),
                'provider' => $schema->string()->required(),
                'mailer' => $schema->string()->required(),
                'from_address' => $schema->string()->nullable()->required(),
                'from_name' => $schema->string()->nullable()->required(),
                'reply_to' => $schema->string()->nullable()->required(),
                'domain' => $schema->string()->nullable()->required()->description('The marketing domain campaigns are sent from.'),
                'provider_logs_readable' => $schema->boolean()->required()->description('Whether the provider\'s logs and suppression lists can be read (campaigns.mailgun.api_key).'),
                'test_addresses' => $schema->array()->items($schema->string())->required(),
                'spam_score_check' => $schema->boolean()->required(),
                'notes' => $schema->array()->items($schema->string())->required()->description('What is switched off or missing, in words.'),
            ]),
            'lists' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'kind' => $schema->string()->enum(array_column(ListKind::cases(), 'value'))->required(),
                'double_opt_in' => $schema->boolean()->required(),
                ...$statusCounts,
                'mailable' => $schema->integer()->required()->description('Subscribed people who aren\'t opted out, blocked or sunset.'),
                'tiers' => $schema->object($tierCounts)->required()->description('Subscribed people by engagement tier.'),
            ])),
            'lists_total' => $schema->integer(),
            'campaigns' => $schema->array()->items($schema->object([
                'uuid' => $schema->string()->required(),
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'status' => $schema->string()->enum(array_column(CampaignStatus::cases(), 'value'))->required(),
                'recipient_count' => $schema->integer()->nullable()->required(),
                'scheduled_at' => $schema->string()->nullable()->required(),
                'started_at' => $schema->string()->nullable()->required(),
                'paused_at' => $schema->string()->nullable()->required(),
                'pause_reason' => $schema->string()->nullable()->required(),
            ])),
            'campaigns_total' => $schema->integer(),
            'placeholders' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'value' => $schema->string()->nullable()->required()->description('This app\'s value, where the config gives one.'),
                'in_subject' => $schema->boolean()->required()->description('Whether a subject or preheader may hold it.'),
            ])),
        ];
    }

    public function perform(): ResponseFactory
    {
        return Response::structured(Overview::build());
    }
}
