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
use Mozex\Campaigns\Actions\UpdateListAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Reports\ListSummary;

#[Name('update_list')]
#[Title('Rename or describe a list')]
#[Description('Changes a static list\'s name or description (null clears it). Its key never changes; a dynamic list\'s name comes from the app.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class UpdateListTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required(),
            'name' => $schema->string(),
            'description' => $schema->string()->nullable(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return ['list' => Schemas::list($schema)];
    }

    public function perform(Request $request, UpdateListAction $update): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['sometimes', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $list = $update->execute(CampaignList::forKey($input['key']), array_intersect_key($input, array_flip(UpdateListAction::FIELDS)));

        return Response::structured(['list' => ListSummary::rows([$list], details: true)[0]]);
    }
}
