<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Models\Upload;

/**
 * Deletes expired uploads with their files (links nobody used, and files
 * that arrived and waited past Upload::KEEP_HOURS, used or not), then any
 * file in the uploads folder older than that which no upload names: one a
 * request stored before it failed.
 */
class PruneUploadsAction
{
    /**
     * @return int how many uploads were deleted
     */
    public function execute(): int
    {
        $deleted = 0;

        Upload::query()
            ->where('expires_at', '<=', now())
            ->chunkById(500, function (Collection $uploads) use (&$deleted): void {
                foreach ($uploads as $upload) {
                    /** @var Upload $upload */
                    $upload->deleteFile();
                    $upload->delete();
                    $deleted++;
                }
            });

        $disk = Storage::disk((string) config('campaigns.uploads.disk'));
        $named = Upload::query()->whereNotNull('path')->pluck('path')->flip();
        $before = now()->subHours(Upload::KEEP_HOURS)->getTimestamp();

        foreach ($disk->files(trim((string) config('campaigns.uploads.path'), '/')) as $file) {
            // Only names ReceiveUploadAction writes, so an app's own files in
            // a folder it shares stay where they are.
            if (preg_match(ReceiveUploadAction::FILE_NAME, basename($file)) === 1
                && ! $named->has($file)
                && $disk->lastModified($file) < $before) {
                $disk->delete($file);
            }
        }

        return $deleted;
    }
}
