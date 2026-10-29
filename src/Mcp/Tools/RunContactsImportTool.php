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
use Mozex\Campaigns\Actions\ImportUploadedContactsAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersImports;
use Mozex\Campaigns\Mcp\Tools\Concerns\ImportsUploadedContacts;

#[Name('run_contacts_import')]
#[Title('Run a contacts import')]
#[Description('Imports an uploaded CSV of contacts for real, after preview_contacts_import showed the person you work for its numbers: the same arguments, the same counts. It uses the upload up. Opt-outs and blocks it brings in are kept for good; nothing it does clears one.')]
#[IsOpenWorld(false)]
#[IsDestructive]
class RunContactsImportTool extends CampaignsTool
{
    use AnswersImports;
    use ImportsUploadedContacts;

    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return static::importInput($schema);
    }

    protected function output(JsonSchema $schema): array
    {
        return Schemas::importResult($schema);
    }

    public function perform(Request $request, ImportUploadedContactsAction $import): ResponseFactory
    {
        return Response::structured(static::importAnswer(static::importUpload($request, $import, dryRun: false)));
    }
}
