<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Support\DatabaseErrors;

/**
 * Where a driver's service posts what happened to the emails it sent,
 * answered by the driver the path names whether or not it is the active one,
 * so late events after a switch still land. A driver that isn't configured
 * answers 404 without being built, and one that takes no webhooks 404 too. A
 * 406 tells the service to stop retrying (a bad signature, a stale or
 * replayed request); a 500 makes it retry.
 */
class WebhookController
{
    public function __invoke(Request $request, string $driver, DriverManager $drivers, RecordFeedbackAction $record): Response
    {
        try {
            $receiver = $drivers->configured($driver);

            abort_unless($receiver instanceof ReceivesWebhooks, 404);

            $verified = $receiver->verifyWebhook($request);
        } catch (CampaignsException $exception) {
            // The app isn't set up to verify this driver's webhooks (no
            // signing key, a creator that failed). The route takes anyone's
            // posts, so it is reported once an hour, not on every request,
            // and answered so the service retries.
            $store = config('campaigns.cache_store') ?: null;

            if (Cache::store(is_string($store) ? $store : null)->add('campaigns:webhook-unverifiable:'.$driver, true, 3600)) {
                report($exception);
            }

            return response('', 500);
        }

        if (! $verified) {
            return response('', 406);
        }

        $refused = false;

        foreach ($receiver->parseWebhook($request) as $feedback) {
            if (DatabaseErrors::scrubbed('a provider event', fn (): FeedbackResult => $record->execute($feedback, $driver)) === FeedbackResult::Refused) {
                $refused = true;
            }
        }

        return response('', $refused ? 406 : 200);
    }
}
