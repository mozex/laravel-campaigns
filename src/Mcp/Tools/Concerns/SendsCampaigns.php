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

/**
 * What the sending tools share: a campaign named by its key, and an answer
 * showing where its sending stands after the call (as get_campaign_status
 * shows it).
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

    protected static function progress(Campaign $campaign): ResponseFactory
    {
        return Response::structured([
            'campaign' => CampaignProgress::of($campaign),
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
