<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Imports\ContactsCsv;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * Imports a CSV of contacts sent through an upload link, through
 * ImportContactsAction. A dry run reads the file without using the upload
 * up, so the real run can follow it on the same upload; the real run claims
 * it and deletes the file once done.
 */
class ImportUploadedContactsAction
{
    public function __construct(
        protected ClaimUploadAction $claim,
        protected ImportContactsAction $import,
    ) {}

    /**
     * @param  ?CampaignList  $list  The static list the contacts join; null to record only opt-outs, blocks and engagement.
     * @param  ConsentContext  $context  The import's source (its name); its basis defaults to imported.
     *
     * @throws CampaignsException for an upload that can't be used, a file that can't be read, or an archived or dynamic list
     */
    public function execute(string $uploadId, ?CampaignList $list, ConsentContext $context, bool $dryRun): ImportResult
    {
        // Checked before the claim, so a wrong list never uses the upload up.
        if ($list?->kind === ListKind::Dynamic) {
            throw CampaignsException::dynamicList($list->key);
        }

        if ($list?->archived_at !== null) {
            throw CampaignsException::listArchived($list->key);
        }

        $rows = ContactsCsv::read($this->claim->usable($uploadId, UploadPurpose::Contacts)->contents());

        if ($dryRun) {
            return $this->import->execute($rows, $list, $context, dryRun: true);
        }

        $upload = $this->claim->execute($uploadId, UploadPurpose::Contacts);

        try {
            return $this->import->execute($rows, $list, $context, dryRun: false);
        } finally {
            $upload->deleteFile();
        }
    }
}
