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
use Mozex\Campaigns\Actions\UpdateTemplateAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\TemplateSummary;

#[Name('update_template')]
#[Title('Rename or describe a template')]
#[Description('Changes a template\'s name, description (null clears it) or default mark; only what is given changes. Its key never changes. Its email changes through add_revision.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class UpdateTemplateTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required(),
            'name' => $schema->string(),
            'description' => $schema->string()->nullable(),
            'is_default' => $schema->boolean(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'template' => Schemas::template($schema),
        ];
    }

    public function perform(Request $request, UpdateTemplateAction $update): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_default' => ['sometimes', 'required', 'boolean'],
        ]);

        $template = Template::forKey($input['key']);

        $update->execute($template, array_intersect_key($input, array_flip(UpdateTemplateAction::FIELDS)));
        $template->load('currentRevision.contents:id,revision_id,locale');

        return Response::structured([
            'template' => TemplateSummary::row($template, TemplateSummary::DETAIL_DESCRIPTION_LENGTH),
        ]);
    }
}
