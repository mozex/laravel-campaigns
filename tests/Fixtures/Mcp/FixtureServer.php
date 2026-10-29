<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures\Mcp;

use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Resources\GuideResource;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;

/**
 * The package's server with a read tool and a write tool of the tests' own,
 * so the rules every tool shares can be proven before the real write tools
 * exist. Bound in place of CampaignsServer, which the route resolves
 * through the container.
 */
class FixtureServer extends CampaignsServer
{
    protected array $tools = [
        OverviewTool::class,
        FixtureReadTool::class,
        FixtureWriteTool::class,
    ];

    protected array $resources = [
        GuideResource::class,
    ];
}
