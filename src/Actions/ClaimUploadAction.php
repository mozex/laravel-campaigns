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

        throw static::refusal($upload, $purpose) ?? CampaignsException::uploadUnavailable('it was already used');
    }

    /**
     * The upload, when a claim would take it now, without claiming it: for a
     * preview that reads the file the real run claims later.
     *
     * @throws CampaignsException naming why the upload can't be used
     */
    public function usable(string $uuid, UploadPurpose $purpose): Upload
    {
        $upload = Upload::forId($uuid);
        $refusal = static::refusal($upload, $purpose);

        if ($refusal !== null) {
            throw $refusal;
        }

        return $upload;
    }

    protected static function refusal(Upload $upload, UploadPurpose $purpose): ?CampaignsException
    {
        return match (true) {
            $upload->purpose !== $purpose => CampaignsException::uploadWrongPurpose($purpose, $upload->purpose),
            $upload->claimed_at !== null => CampaignsException::uploadUnavailable('it was already used'),
            $upload->expires_at->isPast() => CampaignsException::uploadExpired(),
            $upload->received_at === null => CampaignsException::uploadUnavailable('no file has arrived through it yet'),
            default => null,
        };
    }
}
