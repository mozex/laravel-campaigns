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
use Mozex\Campaigns\Actions\ImportUploadedContactsAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\AnswersImports;
use Mozex\Campaigns\Mcp\Tools\Concerns\ImportsUploadedContacts;

#[Name('preview_contacts_import')]
#[Title('Preview a contacts import')]
#[Description('What importing an uploaded CSV of contacts would do, with nothing saved: every change made and rolled back, so the counts are the real run\'s. The upload stays for run_contacts_import. The CSV\'s first line names its columns: email, name, first_name, locale, timezone, country, last_opened_at, last_clicked_at, last_active_at, opted_out (true or false), blocked (hard_bounce, complaint, soft_bounces, manual or imported), custom_fields.<name> and meta.<name> (kept in the consent records); comma, semicolon or tab separated, UTF-8, at most 2,000 contacts. Without a list it only records opt-outs, blocks and engagement for people already here.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class PreviewContactsImportTool extends CampaignsTool
{
    use AnswersImports;
    use ImportsUploadedContacts;

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
        return Response::structured(static::importAnswer(static::importUpload($request, $import, dryRun: true)));
    }
}
