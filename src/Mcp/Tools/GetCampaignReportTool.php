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
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\MailboxProvider;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignReport;
use Mozex\Campaigns\Reports\ReportSummary;

#[Name('get_campaign_report')]
#[Title('Get a campaign\'s report')]
#[Description('A campaign\'s numbers, in counts only: its messages by status; its sends (emails the provider took), deliveries, failures by kind, hard bounces, spam reports and unsubscribes (by how they came); the people who opened and clicked, with every open and click by people and by machines (Apple\'s privacy opens and link scanners), and the most clicked links; rates (bounces, spam reports and unsubscribes per send, as the stop-loss measures them; opens and clicks per delivery, machines left out, or per sent email for a driver that reads nothing back, as their basis says); the same by list, language, engagement tier (as it stood at the start) and mailbox provider; a timeline by day in the app\'s timezone; and the queue now. It is worked out when read, so it keeps moving after the campaign completes (late bounces, opens, the reconciler), and is kept once events are pruned. The '.ReportSummary::LINKS.' most clicked links, the '.ReportSummary::BREAKDOWN.' largest lists and languages and the latest '.ReportSummary::DAYS.' days are shown, each with its total.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetCampaignReportTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('The campaign\'s key.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        $row = fn (): array => [
            'messages' => $schema->integer()->required(),
            'sends' => $schema->integer()->required(),
            'delivered' => $schema->integer()->required(),
            'hard_bounces' => $schema->integer()->required(),
            'sender_blocked' => $schema->integer()->required(),
            'complaints' => $schema->integer()->required(),
            'unsubscribes' => $schema->integer()->required(),
            'opened' => $schema->integer()->required(),
            'clicked' => $schema->integer()->required(),
        ];

        $keyed = function (array $keys) use ($schema, $row): array {
            $rows = [];

            foreach ($keys as $key) {
                $rows[$key] = $schema->object($row())->required();
            }

            return $rows;
        };

        $statuses = [];

        foreach (MessageStatus::cases() as $status) {
            $statuses[$status->value] = $schema->integer()->required();
        }

        $people = fn (): array => [
            'people' => $schema->integer()->required(),
            'human' => $schema->integer()->required(),
            'machine' => $schema->integer()->required(),
        ];

        return [
            'campaign' => $schema->object([
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'status' => $schema->string()->required(),
                'recipients' => $schema->integer()->nullable()->required(),
                'started_at' => $schema->string()->nullable()->required(),
                'completed_at' => $schema->string()->nullable()->required(),
                'canceled_at' => $schema->string()->nullable()->required(),
            ]),
            'messages' => $schema->integer(),
            'statuses' => $schema->object($statuses),
            'sends' => $schema->integer()->description('Emails the provider took.'),
            'unsent' => $schema->integer()->description('Still pending or queued.'),
            'delivered' => $schema->integer(),
            'failures' => $schema->object()->description('Failed messages by kind (as get_campaign_status names them).'),
            'hard_bounces' => $schema->integer(),
            'complaints' => $schema->integer()->description('Spam reports.'),
            'unsubscribes' => $schema->object([
                'total' => $schema->integer()->required(),
                'one_click' => $schema->integer()->required()->description('A mail app\'s one-click unsubscribe.'),
                'page' => $schema->integer()->required()->description('The unsubscribe page.'),
                'provider' => $schema->integer()->required()->description('The provider\'s own unsubscribe link.'),
                'other' => $schema->integer()->required(),
            ]),
            'opens' => $schema->object($people())->description('people opened at least once; human and machine count every open the provider reported.'),
            'clicks' => $schema->object($people()),
            'rates' => $schema->object([
                'delivery' => $schema->number()->nullable()->required(),
                'hard_bounce' => $schema->number()->nullable()->required(),
                'complaint' => $schema->number()->nullable()->required(),
                'unsubscribe' => $schema->number()->nullable()->required(),
                'open' => $schema->number()->nullable()->required(),
                'click' => $schema->number()->nullable()->required(),
                'basis' => $schema->string()->enum(['delivered', 'sent', 'delivered_or_sent'])->required()->description('What opens and clicks divide by: delivered emails; sent ones, when the campaign went through a driver that reads nothing back (no webhooks, no logs), so no delivery is ever known and the delivery rate is null; or both, after a switch between the two kinds mid-campaign.'),
            ])->description('Percent, to three places; null with nothing to divide.'),
            'links' => $schema->array()->items($schema->object([
                'url' => $schema->string()->required(),
                'human' => $schema->integer()->required(),
                'machine' => $schema->integer()->required(),
            ]))->description('The most clicked by people first; each address cut to '.ReportSummary::URL_LENGTH.' characters.'),
            'links_total' => $schema->integer(),
            'by_list' => $schema->object()->description('By target list key, the largest first.'),
            'lists_total' => $schema->integer(),
            'by_locale' => $schema->object()->description('By the language each email went in, the largest first.'),
            'locales_total' => $schema->integer(),
            'by_tier' => $schema->object($keyed(array_column(EngagementTier::cases(), 'value'))),
            'by_provider' => $schema->object($keyed([...array_column(MailboxProvider::cases(), 'value'), CampaignReport::UNKNOWN_PROVIDER])),
            'timeline' => $schema->object()->description('By day (the app\'s timezone), the latest '.ReportSummary::DAYS.': what was sent, delivered, failed, bounced hard, opened, clicked, reported as spam and unsubscribed that day.'),
            'days_total' => $schema->integer(),
            'computed_at' => $schema->string()->nullable(),
            'frozen_at' => $schema->string()->nullable()->description('When it was kept on the campaign, once its events were to be pruned.'),
            'queue' => $schema->object()->description('Where its sending stands now, as get_campaign_status shows it.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
        ]);

        return Response::structured(ReportSummary::of(CampaignReport::for(Campaign::forKey($input['key']))));
    }
}
