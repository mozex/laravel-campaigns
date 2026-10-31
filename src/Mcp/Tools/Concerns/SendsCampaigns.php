<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignProgress;
use Mozex\Campaigns\Reports\Overview;

/**
 * What the sending tools share: a campaign named by its key, and an answer
 * showing where its sending stands after the call (as get_campaign_status
 * shows it). Starting and resuming also warn when the driver reads nothing
 * back from its service, which the stop-loss and the blocks depend on.
 */
trait SendsCampaigns
{
    /**
     * @return array<string, Type>
     */
    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::progress($schema),
        ];
    }

    protected static function keyInput(JsonSchema $schema): Type
    {
        return $schema->string()->required()->description('The campaign\'s key.');
    }

    protected static function paceInput(JsonSchema $schema): Type
    {
        return $schema->object()->description(Schemas::PACE);
    }

    protected static function warningOutput(JsonSchema $schema): Type
    {
        return $schema->string()->nullable()->description('Set when the driver sending the campaign reads nothing back from its service (no webhooks, no logs): what that leaves the stop-loss and the blocks without. Tell the person you work for.');
    }

    protected static function progress(Campaign $campaign, bool $warn = false): ResponseFactory
    {
        return Response::structured([
            'campaign' => CampaignProgress::of($campaign),
            ...($warn ? ['warning' => Overview::readsNothingBack()] : []),
        ]);
    }

    /**
     * The pace as given: an empty object arrives as an empty array.
     *
     * @return array<string, mixed>
     */
    protected static function pace(mixed $given): array
    {
        /** @var array<string, mixed> */
        return is_array($given) ? $given : [];
    }
}
