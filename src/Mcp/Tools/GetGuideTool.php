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
use Mozex\Campaigns\Mcp\Resources\GuideResource;
use Mozex\Campaigns\Reports\ResourcePage;

#[Name('get_guide')]
#[Title('Get the guide')]
#[Description('The full workflow for running this app\'s campaigns, from writing an email to its report, as the campaigns://guide resource gives it, and the app\'s own read-only resources (a brand guide, its products and the like) by name. Given a resource, it reads that resource a page at a time instead. Read the guide before writing or sending a campaign.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetGuideTool extends CampaignsTool
{
    /**
     * How many of the app's resources the guide's answer names beside it.
     */
    public const RESOURCES = 25;

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('One of the app\'s resources by name, to read it instead of the guide.'),
            'page' => $schema->integer()->description('The resource\'s page; 1 by default.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'guide' => $schema->string()->nullable()->description('The guide, as Markdown; null when a resource was read.'),
            'resources' => $schema->array()->items($schema->object([
                'name' => $schema->string()->required(),
                'description' => $schema->string()->nullable()->required(),
            ]))->nullable()->description('The app\'s own resources, the first '.static::RESOURCES.'; null when a resource was read.'),
            'resources_total' => $schema->integer()->nullable(),
            'page' => $schema->object([
                'resource' => $schema->string()->required(),
                'description' => $schema->string()->nullable()->required(),
                'page' => $schema->integer()->required(),
                'items' => $schema->array()->required(),
                'next_page' => $schema->integer()->nullable()->required(),
            ])->nullable()->description('A page of the resource asked for.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'resource' => ['nullable', 'string'],
            'page' => static::PAGE_RULES,
        ]);

        $resource = $input['resource'] ?? null;

        // A page is the app's own size, held to the answer limit on its
        // own, so it comes alone.
        if ($resource !== null) {
            return Response::structured([
                'guide' => null,
                'resources' => null,
                'resources_total' => null,
                'page' => ResourcePage::read($resource, (int) ($input['page'] ?? 1)),
            ]);
        }

        $resources = ResourcePage::list();

        return Response::structured([
            'guide' => GuideResource::text(),
            'resources' => array_slice($resources, 0, static::RESOURCES),
            'resources_total' => count($resources),
            'page' => null,
        ]);
    }
}
