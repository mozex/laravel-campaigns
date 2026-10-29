<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\Upload;

/**
 * Hands an upload's file to the tool that uses it, once: one conditional
 * update claims it, so two calls naming the same upload can't both use it.
 * The caller reads the file with Upload::contents() and deletes it with
 * Upload::deleteFile() once read.
 */
class ClaimUploadAction
{
    /**
     * @throws CampaignsException naming why the upload can't be used
     */
    public function execute(string $uuid, UploadPurpose $purpose): Upload
    {
        $upload = Upload::forId($uuid);

        $claimed = Upload::query()
            ->whereKey($upload->id)
            ->where('purpose', $purpose)
            ->whereNotNull('received_at')
            ->whereNull('claimed_at')
            ->where('expires_at', '>', now())
            ->update(['claimed_at' => now(), 'updated_at' => now()]);

        $upload->refresh();

        if ($claimed === 1) {
            return $upload;
        }

        throw match (true) {
            $upload->purpose !== $purpose => CampaignsException::uploadWrongPurpose($purpose, $upload->purpose),
            $upload->claimed_at !== null => CampaignsException::uploadUnavailable('it was already used'),
            $upload->expires_at->isPast() => CampaignsException::uploadExpired(),
            default => CampaignsException::uploadUnavailable('no file has arrived through it yet'),
        };
    }
}
