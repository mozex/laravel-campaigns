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
use Mozex\Campaigns\Actions\AddRevisionAction;
use Mozex\Campaigns\Actions\AddUploadedRevisionAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersUploadedEmails;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Mcp\Tools\Concerns\TakesEmailContent;
use Mozex\Campaigns\Reports\RevisionSummary;

#[Name('add_revision')]
#[Title('Save a new version of an email')]
#[Description('Saves a template\'s or a draft campaign\'s email as its new current revision, from upload_id (an email sent through an email upload link, with subjects and preheaders by language) or contents (inline). Nothing is overwritten: every version stays in the history, and restore_revision brings one back. A started or scheduled campaign\'s email never changes. The same rules as create_template apply, and a refusal lists every problem at once.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class AddRevisionTool extends CampaignsTool
{
    use AnswersUploadedEmails;
    use NamesEmailOwner;
    use TakesEmailContent;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'note' => $schema->string()->description('What changed, kept in the history.'),
            ...static::contentInput($schema),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'revision' => $schema->object(Schemas::revisionRow($schema)),
            ...static::uploadOutput($schema),
        ];
    }

    public function perform(Request $request, AddRevisionAction $add, AddUploadedRevisionAction $addUploaded): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'note' => ['nullable', 'string'],
            ...static::contentRules(),
            'contents' => ['nullable', 'array', 'list', 'min:1', 'required_without:upload_id'],
        ]);

        $owner = static::owner($input);
        $note = $input['note'] ?? null;
        $uploaded = null;

        if (isset($input['upload_id'])) {
            $uploaded = $addUploaded->execute(
                $owner,
                $input['upload_id'],
                static::byLocale($input, 'subjects'),
                static::byLocale($input, 'preheaders'),
                $note,
                static::author(),
            );
            $revision = $uploaded->revision;
        } else {
            $revision = $add->execute($owner, static::inlineContents($input), $note, static::author());
        }

        return Response::structured([
            'revision' => RevisionSummary::row($revision),
            ...static::uploadAnswer($uploaded),
        ]);
    }
}
