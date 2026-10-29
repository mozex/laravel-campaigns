<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;

#[Name('fixture_read')]
#[IsReadOnly]
class FixtureReadTool extends CampaignsTool
{
    protected function output(JsonSchema $schema): array
    {
        return ['read' => $schema->boolean()];
    }

    public function perform(): ResponseFactory
    {
        return Response::structured(['read' => true]);
    }
}
