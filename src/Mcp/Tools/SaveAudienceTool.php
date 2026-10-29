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
use Mozex\Campaigns\Actions\SaveAudienceAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\ShownText;

#[Name('save_audience')]
#[Title('Save an audience')]
#[Description('Saves a named set of the built-in filters, or replaces the filters saved under that key. A campaign naming it ({"saved_audience": key} in its audience) reads its filters when it starts, so replacing them changes who every campaign not yet started reaches. The filters: '.Schemas::FILTERS.'.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class SaveAudienceTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('Lower-case letters, digits, dots, dashes and underscores, at most '.Keys::MAX_LENGTH.' characters.'),
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'filters' => $schema->object()->required(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string(),
            'name' => $schema->string(),
            'filters' => $schema->string()->description('As saved, as JSON, cut to '.CampaignSummary::AUDIENCE_TEXT_LENGTH.' characters.'),
            'created' => $schema->boolean()->description('False when it replaced an audience saved under the key.'),
        ];
    }

    public function perform(Request $request, SaveAudienceAction $save): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'filters' => ['present', 'array'],
        ]);

        /** @var array<string, mixed> $filters */
        $filters = $input['filters'];
        $audience = $save->execute($input['key'], $input['name'], $filters, $input['description'] ?? null);

        return Response::structured([
            'key' => $audience->key,
            'name' => ShownText::of($audience->name, CampaignSummary::NAME_LENGTH),
            'filters' => CampaignSummary::jsonText($audience->filters) ?? '{}',
            'created' => $audience->wasRecentlyCreated,
        ]);
    }
}
