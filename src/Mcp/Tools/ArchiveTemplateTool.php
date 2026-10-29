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
use Mozex\Campaigns\Actions\ArchiveTemplateAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\TemplateSummary;

#[Name('archive_template')]
#[Title('Archive or restore a template')]
#[Description('Archives a template, or restores it with archived false. An archived template is hidden from list_templates; nothing is deleted, its key still finds it, and campaigns made from it keep their own copy of its email.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class ArchiveTemplateTool extends CampaignsTool
{
    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required(),
            'archived' => $schema->boolean()->description('False restores it. Default true.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->description('False when the template was already in that state.'),
            'template' => Schemas::template($schema),
        ];
    }

    public function perform(Request $request, ArchiveTemplateAction $archive): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $template = Template::forKey($input['key']);
        $changed = $archive->execute($template, (bool) ($input['archived'] ?? true));
        $template->load('currentRevision.contents:id,revision_id,locale');

        return Response::structured([
            'changed' => $changed,
            'template' => TemplateSummary::row($template),
        ]);
    }
}
