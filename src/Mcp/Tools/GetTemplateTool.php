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
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\RevisionSummary;
use Mozex\Campaigns\Reports\TemplateSummary;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Templates\StarterTemplates;

#[Name('get_template')]
#[Title('Get a template')]
#[Description('One template: its details and its current email (or the revision given), each language\'s subject and preheader, its check results\' state, and links valid '.Links::DOWNLOAD_MINUTES.' minutes to download the email\'s files: a zip laid out as an upload takes it, and the default language\'s HTML. To change it, download, edit, and send it back through an email upload link to add_revision, passing the subjects and preheaders, which aren\'t in the files. A starter template\'s key answers its HTML inline instead.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetTemplateTool extends CampaignsTool
{
    use NamesEmailOwner;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('The template\'s key, or a starter template\'s.'),
            'revision' => $schema->integer()->min(1)->description('A revision\'s number; the current one by default.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'template' => Schemas::template($schema),
            'revisions' => $schema->integer()->description('How many revisions it has; list_revisions lists them.'),
            'revision' => Schemas::revision($schema),
            'starter' => $schema->object([
                'key' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'versions' => $schema->array()->items($schema->object([
                    'locale' => $schema->string()->required(),
                    'subject' => $schema->string()->required(),
                    'preheader' => $schema->string()->nullable()->required(),
                    'html' => $schema->string()->required(),
                ]))->required(),
            ])->description('Only for a starter template\'s key.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'revision' => ['nullable', 'integer', 'min:1'],
        ]);

        $starter = StarterTemplates::find($input['key']);

        if ($starter !== null) {
            return Response::structured(['starter' => [
                'key' => $starter->key,
                'name' => $starter->name,
                'description' => $starter->description,
                'versions' => array_map(fn (EmailContent $content): array => [
                    'locale' => $content->locale,
                    'subject' => $content->subject,
                    'preheader' => $content->preheader,
                    'html' => $content->html,
                ], $starter->contents),
            ]]);
        }

        $template = Template::forKey($input['key']);
        $revision = static::revision($template, isset($input['revision']) ? (int) $input['revision'] : null);
        $revision->load(['contents', 'frozenFrom:id,number']);
        $template->setRelation('currentRevision', $template->currentRevision()->with('contents:id,revision_id,locale')->first());

        return Response::structured([
            'template' => TemplateSummary::row($template, TemplateSummary::DETAIL_DESCRIPTION_LENGTH),
            'revisions' => $template->revisions()->count(),
            'revision' => RevisionSummary::detail($revision, Links::downloadsExpireAt()),
        ]);
    }
}
