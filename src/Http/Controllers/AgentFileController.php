<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ReceiveUploadAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Uploads\RevisionArchive;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The signed links the MCP tools hand an agent (Support\Links): the upload
 * link a file is PUT to with curl, and a revision's email to download. The
 * signature is the authorization, so nobody is signed in here. Every refusal
 * is answered here as JSON with its own status, never thrown to the app's
 * exception handler, which an app may own for these paths.
 */
class AgentFileController
{
    public const FILES = ['email.zip', 'email.html'];

    /** Requests a link takes a minute: a few retries, never a flood. */
    public const PER_MINUTE = 30;

    public function upload(Request $request, string $upload, ReceiveUploadAction $receive): JsonResponse
    {
        try {
            $this->admit($request, "upload:{$upload}");

            $declared = $request->header('Content-Length');

            $received = $receive->execute(
                $upload,
                $request->getContent(true),
                $request->header('Content-Type'),
                is_numeric($declared) ? (int) $declared : null,
            );
        } catch (CampaignsException $exception) {
            return static::refused($exception);
        } catch (Throwable $exception) {
            // A disk that throws (its 'throw' option on) is this side's
            // failure, answered here like the rest, never by the app's handler.
            return static::failed($exception);
        }

        return response()->json([
            'upload_id' => $received->uuid,
            'size' => $received->size,
            'message' => "Received. Pass the upload_id to the tool that takes {$received->purpose->label()} within ".Upload::KEEP_HOURS.' hours.',
        ], 201);
    }

    public function download(Request $request, string $revision, string $file): Response
    {
        try {
            $this->admit($request, "download:{$revision}:{$file}");

            $found = Str::isUuid($revision) ? Revision::query()->where('uuid', strtolower($revision))->first() : null;

            if ($found === null) {
                throw CampaignsException::downloadNotFound();
            }

            $zip = $file === 'email.zip';
            $bytes = $zip ? RevisionArchive::zip($found) : RevisionArchive::html($found);
        } catch (CampaignsException $exception) {
            return static::refused($exception);
        } catch (Throwable $exception) {
            return static::failed($exception);
        }

        // Always an attachment, never a page: the HTML is what an agent
        // uploaded, and must not run on the app's origin if a browser opens
        // the link.
        return response($bytes, 200, [
            'Content-Type' => $zip ? 'application/zip' : 'text/html; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$file}\"",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * Lets a request through when its signature is right and unexpired and
     * its link isn't over its rate. The signature is checked without the
     * host, as Support\Links signs it.
     *
     * @throws CampaignsException
     */
    protected function admit(Request $request, string $link): void
    {
        /** @var UrlGenerator $urls */
        $urls = app('url');

        if (! $urls->hasCorrectSignature($request, absolute: false)) {
            throw CampaignsException::linkInvalid();
        }

        if (! $urls->signatureHasNotExpired($request)) {
            throw CampaignsException::linkExpired();
        }

        $key = 'campaigns.files:'.$link;

        if (RateLimiter::tooManyAttempts($key, static::PER_MINUTE)) {
            throw CampaignsException::linkThrottled(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key);
    }

    protected static function refused(CampaignsException $exception): JsonResponse
    {
        $status = $exception->getCode();
        $status = $status >= 400 && $status < 600 ? $status : 422;

        // Storing or building failed on this side, which the agent can't fix.
        if ($status >= 500) {
            report($exception);
        }

        return response()->json(['error' => $exception->getMessage()], $status);
    }

    protected static function failed(Throwable $exception): JsonResponse
    {
        report($exception);

        return response()->json(['error' => 'The server failed to handle the file. Try again later.'], 500);
    }
}
