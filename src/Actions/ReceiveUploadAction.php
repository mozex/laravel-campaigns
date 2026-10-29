<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Upload;
use Throwable;

/**
 * Takes the file sent to an upload link: once, before the link expires,
 * and only up to its purpose's limit (Upload::maxSize()), counted on the
 * bytes as they arrive; a length the sender declares over it is refused
 * before anything is read. The file then waits on campaigns.uploads.disk,
 * a private disk, for Upload::KEEP_HOURS.
 *
 * A refusal's exception code is the HTTP status the endpoint answers with:
 * 404 for an unknown link, 409 for one a file already came through, 410
 * for an expired one, 413 for a file over the limit, 400 for an empty one.
 */
class ReceiveUploadAction
{
    protected const CHUNK = 65536;

    /** The name of a received file: the upload's uuid and a random suffix. */
    public const FILE_NAME = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}-[A-Za-z0-9]{16}\z/';

    /**
     * @param  resource|string  $body  the request body, as a stream or a string
     * @param  ?int  $declaredSize  the length the sender declares (Content-Length), when known
     *
     * @throws CampaignsException
     */
    public function execute(string $uuid, mixed $body, ?string $mime = null, ?int $declaredSize = null): Upload
    {
        $upload = Upload::forId($uuid);

        if ($upload->received_at !== null) {
            throw CampaignsException::uploadUnavailable('a file already arrived through it');
        }

        if ($upload->expires_at->isPast()) {
            throw CampaignsException::uploadExpired();
        }

        $max = Upload::maxSize($upload->purpose);

        if ($declaredSize !== null && $declaredSize > $max) {
            throw CampaignsException::uploadTooLarge($max);
        }

        [$stream, $size] = $this->buffer($body, $max);

        $disk = (string) config('campaigns.uploads.disk');
        // Its own name per attempt, so two requests racing on one link never
        // write the same file; the loser deletes its own.
        $path = trim((string) config('campaigns.uploads.path'), '/').'/'.$upload->uuid.'-'.Str::random(16);

        try {
            if (! Storage::disk($disk)->writeStream($path, $stream)) {
                throw CampaignsException::uploadNotStored();
            }
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        } finally {
            // A storage adapter may have closed it already.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = Upload::query()
            ->whereKey($upload->id)
            ->whereNull('received_at')
            ->where('expires_at', '>', now())
            ->update([
                'disk' => $disk,
                'path' => $path,
                'size' => $size,
                'mime' => $this->mime($mime),
                'received_at' => now(),
                'expires_at' => now()->addHours(Upload::KEEP_HOURS),
                'updated_at' => now(),
            ]);

        if ($stored === 0) {
            Storage::disk($disk)->delete($path);

            throw $upload->refresh()->received_at !== null
                ? CampaignsException::uploadUnavailable('a file already arrived through it')
                : CampaignsException::uploadExpired();
        }

        return $upload->refresh();
    }

    /**
     * The body copied into a temporary stream, counted as it goes, so a file
     * over the limit is refused before it is stored and never held whole in
     * memory.
     *
     * @param  resource|string  $body
     * @return array{0: resource, 1: int}
     *
     * @throws CampaignsException
     */
    protected function buffer(mixed $body, int $max): array
    {
        $buffer = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($buffer === false) {
            throw CampaignsException::uploadNotStored();
        }

        $size = 0;

        try {
            if (is_string($body)) {
                $size = strlen($body);

                if ($size > $max) {
                    throw CampaignsException::uploadTooLarge($max);
                }

                static::write($buffer, $body);
            }

            if (is_resource($body)) {
                while (! feof($body)) {
                    $chunk = fread($body, static::CHUNK);

                    // A read that fails partway would store a cut file as
                    // the whole upload.
                    if ($chunk === false) {
                        throw CampaignsException::uploadNotStored();
                    }

                    $size += strlen($chunk);

                    if ($size > $max) {
                        throw CampaignsException::uploadTooLarge($max);
                    }

                    static::write($buffer, $chunk);
                }
            }

            // A body that is neither a string nor an open stream counts as
            // empty too.
            if ($size === 0) {
                throw CampaignsException::uploadEmpty();
            }
        } catch (Throwable $exception) {
            fclose($buffer);

            throw $exception;
        }

        rewind($buffer);

        return [$buffer, $size];
    }

    /**
     * A short write (a full temporary disk) would store a cut file under
     * the full byte count.
     *
     * @param  resource  $buffer
     *
     * @throws CampaignsException
     */
    protected static function write(mixed $buffer, string $bytes): void
    {
        if (fwrite($buffer, $bytes) !== strlen($bytes)) {
            throw CampaignsException::uploadNotStored();
        }
    }

    /**
     * The content type the sender declared, kept for reference only: what
     * a file is gets read from its bytes.
     */
    protected function mime(?string $mime): ?string
    {
        if ($mime === null || preg_match('/^[\x21-\x7E][\x20-\x7E]*\z/', $mime) !== 1) {
            return null;
        }

        return substr($mime, 0, 127);
    }
}
