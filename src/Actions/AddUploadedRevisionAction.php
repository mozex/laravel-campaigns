<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Assets\AssetLibrary;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Support\TextValue;
use Mozex\Campaigns\Uploads\UploadedRevision;

/**
 * Saves an uploaded email as a template's or a draft campaign's new
 * revision: the upload is read and checked whole first, then its images go
 * into the asset library and the revision is added in one transaction.
 */
class AddUploadedRevisionAction
{
    public function __construct(
        protected ReadEmailUploadAction $read,
        protected AssetLibrary $library,
        protected AddRevisionAction $addRevision,
    ) {}

    /**
     * @param  array<string, string>  $subjects  by locale; a version without one takes its HTML's <title>
     * @param  array<string, string>  $preheaders  by locale
     *
     * @throws CampaignsException
     */
    public function execute(
        Template|Campaign $owner,
        string $uploadId,
        array $subjects = [],
        array $preheaders = [],
        ?string $note = null,
        ?string $author = null,
    ): UploadedRevision {
        // Refused before the one-time upload is used up; AddRevisionAction
        // asks again under the campaign's lock.
        if ($owner instanceof Campaign && $owner->status !== CampaignStatus::Draft) {
            throw CampaignsException::campaignNotDraft($owner->status->value);
        }

        // Likewise before the upload is used up: AddRevisionAction checks
        // them again.
        TextValue::optional('note', $note, 255);
        TextValue::optional('author', $author, 255);

        $email = $this->read->execute($uploadId, $subjects, $preheaders);

        return $owner->getConnection()->transaction(fn (): UploadedRevision => new UploadedRevision(
            assets: $this->library->store($email->images, $author),
            revision: $this->addRevision->execute($owner, $email->contents, $note, $author),
            unusedImages: $email->unusedImages,
        ));
    }
}
