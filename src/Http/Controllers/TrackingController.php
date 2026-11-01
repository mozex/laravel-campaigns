<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignLink;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Support\DatabaseErrors;
use Mozex\Campaigns\Support\HitClassifier;
use Mozex\Campaigns\Support\Token;
use Throwable;

/**
 * The package's own open pixel and tracked links. Each hit is recorded as an
 * event of the reserved "campaigns" driver through RecordFeedbackAction, so
 * opens and clicks count as every driver's other events do, the same for
 * every driver; a test email's are stored against it and change nothing.
 * The token is the email's tracking token, never its pages' one, which opens
 * the person's unsubscribe page and would go along with a forwarded link.
 *
 * Whatever happens the visitor gets their answer: the pixel always, the
 * redirect for every known link. Nothing is recorded for an unknown token, a
 * link of another email's revision, or past HITS_PER_MINUTE for one email,
 * and an error while recording (the database's scrubbed, the queue's or the
 * cache's) is reported. Opening either changes nothing else: an open or a
 * click proves the email went out, and counts as engagement once its timing
 * is checked (ApplyFeedbackAction).
 */
class TrackingController extends PageController
{
    /**
     * The hits one email records a minute, past which it is still answered:
     * enough for a person opening and clicking, not for a loop.
     */
    public const HITS_PER_MINUTE = 30;

    /**
     * A transparent GIF of one pixel, 43 bytes.
     */
    protected const PIXEL = 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

    public function open(Request $request, string $token, RecordFeedbackAction $record): Response
    {
        $this->record($request, $token, FeedbackType::Opened, null, $record);

        return response((string) base64_decode(static::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, private, max-age=0',
        ]);
    }

    public function click(Request $request, string $key, string $token, RecordFeedbackAction $record): Response|RedirectResponse
    {
        $link = CampaignLink::query()->where('key', $key)->first();

        if ($link === null) {
            return $this->invalid();
        }

        $this->record($request, $token, FeedbackType::Clicked, $link, $record);

        return new RedirectResponse($link->url, 302);
    }

    protected function record(Request $request, string $token, FeedbackType $type, ?CampaignLink $link, RecordFeedbackAction $record): void
    {
        $hash = Token::hash($token);

        try {
            DatabaseErrors::scrubbed('an open or a click', function () use ($request, $hash, $type, $link, $record): void {
                $email = CampaignMessage::query()->where('tracking_token_hash', $hash)->first()
                    ?? TestSend::query()->where('tracking_token_hash', $hash)->first();

                if ($email === null || ($link !== null && $link->revision_id !== $this->revisionId($email)) || ! $this->allows($hash)) {
                    return;
                }

                $record->execute(new Feedback(
                    providerEventId: (string) Str::uuid(),
                    type: $type,
                    occurredAt: now(),
                    messageUuid: $email instanceof CampaignMessage ? $email->uuid : null,
                    testSendUuid: $email instanceof TestSend ? $email->uuid : null,
                    bot: HitClassifier::machine($request->method(), $request->userAgent()),
                    url: $link?->url,
                ), DriverManager::RESERVED);
            });
        } catch (Throwable $exception) {
            // Whatever failed (the database, scrubbed; the queue or the
            // limiter's cache), the visitor still gets the pixel or their
            // page. A stored event whose job wasn't queued is queued again
            // by the reconciler.
            report($exception);
        }
    }

    /**
     * The revision the email was made of: its campaign's frozen one, or the
     * test's own.
     */
    protected function revisionId(CampaignMessage|TestSend $email): ?int
    {
        if ($email instanceof TestSend) {
            return $email->revision_id;
        }

        $id = Campaign::query()->whereKey($email->campaign_id)->value('frozen_revision_id');

        return is_numeric($id) ? (int) $id : null;
    }

    protected function allows(string $hash): bool
    {
        $key = 'campaigns.tracking:'.$hash;

        if (RateLimiter::tooManyAttempts($key, static::HITS_PER_MINUTE)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
