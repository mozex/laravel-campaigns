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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\CreateTemplateAction;
use Mozex\Campaigns\Actions\CreateTemplateFromUploadAction;
use Mozex\Campaigns\Actions\DuplicateTemplateAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersUploadedEmails;
use Mozex\Campaigns\Mcp\Tools\Concerns\TakesEmailContent;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Reports\RevisionSummary;
use Mozex\Campaigns\Reports\TemplateSummary;
use Mozex\Campaigns\Support\Keys;

#[Name('create_template')]
#[Title('Create a template')]
#[Description('A new template with its first revision, from one of: from (another template\'s key or a starter\'s, whose current email it copies), upload_id (an email sent through an email upload link, with subjects and preheaders by language) or contents (the email inline). Every placeholder must be one the overview lists, the default language must be there, and the HTML and the text each need {{footer}} or an unsubscribe link: a refusal lists every problem at once. A refused upload is used up: send it again through a new link.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class CreateTemplateTool extends CampaignsTool
{
    use AnswersUploadedEmails;
    use TakesEmailContent;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('Lower-case letters, digits, dots, dashes and underscores, at most '.Keys::MAX_LENGTH.' characters. It never changes.'),
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'is_default' => $schema->boolean()->description('Mark it one of the app\'s defaults to start from. Default false.'),
            'note' => $schema->string()->description('What this first version is, kept in its history. With from, the history says what it was copied from instead.'),
            'from' => $schema->string()->description('Copy another template\'s current email, or a starter template\'s.'),
            ...static::contentInput($schema),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'template' => Schemas::template($schema),
            'revision' => $schema->object(Schemas::revisionRow($schema)),
            ...static::uploadOutput($schema),
        ];
    }

    public function perform(
        Request $request,
        CreateTemplateAction $create,
        CreateTemplateFromUploadAction $createFromUpload,
        DuplicateTemplateAction $duplicate,
    ): ResponseFactory {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string'],
            'from' => ['nullable', 'string', 'required_without_all:upload_id,contents', 'prohibits:upload_id,contents'],
            ...static::contentRules(),
        ]);

        $description = $input['description'] ?? null;
        $isDefault = (bool) ($input['is_default'] ?? false);
        $note = $input['note'] ?? null;
        $uploaded = null;

        if (isset($input['upload_id'])) {
            $uploaded = $createFromUpload->execute(
                key: $input['key'],
                name: $input['name'],
                uploadId: $input['upload_id'],
                subjects: static::byLocale($input, 'subjects'),
                preheaders: static::byLocale($input, 'preheaders'),
                description: $description,
                isDefault: $isDefault,
                note: $note,
                author: static::author(),
            );
            $template = $uploaded->template;
        } elseif (isset($input['from'])) {
            $template = $duplicate->execute($input['from'], $input['key'], $input['name'], static::author(), $description, $isDefault);
        } else {
            $template = $create->execute($input['key'], $input['name'], static::inlineContents($input), $description, $isDefault, $note, static::author());
        }

        /** @var Revision $revision */
        $revision = $template->currentRevision()->with('contents:id,revision_id,locale')->firstOrFail();
        $template->setRelation('currentRevision', $revision);

        return Response::structured([
            'template' => TemplateSummary::row($template, TemplateSummary::DETAIL_DESCRIPTION_LENGTH),
            'revision' => RevisionSummary::row($revision),
            ...static::uploadAnswer($uploaded),
        ]);
    }
}
