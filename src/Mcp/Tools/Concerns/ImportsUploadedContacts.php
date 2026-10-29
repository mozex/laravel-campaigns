<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Mozex\Campaigns\Actions\ImportUploadedContactsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Mcp\Tools\CreateListTool;
use Mozex\Campaigns\Models\CampaignList;

/**
 * What an import's preview and its run share: the same arguments, so the
 * run does what the preview counted.
 */
trait ImportsUploadedContacts
{
    /**
     * @return array<string, Type>
     */
    protected static function importInput(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required()->description('The id of the upload the CSV was sent through, for a list of contacts.'),
            'source' => $schema->string()->required()->description('A name for this import, kept in each contact\'s consent records, such as where the list came from: conference-2026.'),
            'list' => $schema->string()->description('The static list\'s key; leave it out to record only opt-outs, blocks and engagement.'),
            'basis' => $schema->string()->enum(array_column(CreateListTool::BASES, 'value'))->description('Why the app may email them; imported by default.'),
        ];
    }

    /**
     * @throws CampaignsException
     */
    protected static function importUpload(Request $request, ImportUploadedContactsAction $import, bool $dryRun): ImportResult
    {
        $input = $request->validate([
            'upload_id' => ['required', 'string'],
            'source' => ['required', 'string'],
            'list' => ['nullable', 'string'],
            'basis' => ['nullable', 'string', 'in:'.implode(',', array_column(CreateListTool::BASES, 'value'))],
        ]);

        return $import->execute(
            $input['upload_id'],
            isset($input['list']) ? CampaignList::forKey($input['list']) : null,
            static::agentContext($input['source'], isset($input['basis']) ? ConsentBasis::from($input['basis']) : null),
            $dryRun,
        );
    }
}
