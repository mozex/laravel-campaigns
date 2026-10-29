<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use RuntimeException;

/**
 * A write tool that answers as its outcome argument asks: done, refused by
 * the package, invalid input, an answer past the limit, or a crash.
 */
#[Name('fixture_write')]
#[IsDestructive]
class FixtureWriteTool extends CampaignsTool
{
    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return ['outcome' => $schema->string()->required()];
    }

    protected function output(JsonSchema $schema): array
    {
        return ['done' => $schema->boolean(), 'text' => $schema->string()];
    }

    public function perform(Request $request): ResponseFactory
    {
        $outcome = $request->validate(['outcome' => ['required', 'string', 'in:done,refuse,invalid,huge,crash']])['outcome'];

        return match ($outcome) {
            'refuse' => throw new CampaignsException('The package said no.'),
            'invalid' => Response::structured($request->validate(['missing' => ['required']])),
            'huge' => Response::structured(['text' => str_repeat('a', CampaignsTool::RESULT_LIMIT)]),
            'crash' => throw new RuntimeException('Something broke with secret details.'),
            default => Response::structured(['done' => true]),
        };
    }
}
