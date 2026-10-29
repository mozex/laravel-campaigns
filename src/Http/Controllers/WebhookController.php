<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\FeedbackResult;

/**
 * Where the provider posts what happened to the emails it sent. A 406 tells
 * Mailgun to stop retrying (a bad signature, a stale or replayed request);
 * a 500 makes it retry for about 8 hours.
 */
class WebhookController
{
    public function __invoke(Request $request, CampaignProvider $provider, RecordFeedbackAction $record): Response
    {
        try {
            $verified = $provider->verifyWebhook($request);
        } catch (CampaignsException $exception) {
            // The app isn't set up to verify webhooks (no signing key). The
            // route takes anyone's posts, so it is reported once an hour,
            // not on every request, and answered so Mailgun retries.
            $store = config('campaigns.cache_store') ?: null;

            if (Cache::store(is_string($store) ? $store : null)->add('campaigns:webhook-unverifiable', true, 3600)) {
                report($exception);
            }

            return response('', 500);
        }

        if (! $verified) {
            return response('', 406);
        }

        $refused = false;

        foreach ($provider->parseWebhook($request) as $feedback) {
            if ($record->execute($feedback) === FeedbackResult::Refused) {
                $refused = true;
            }
        }

        return response('', $refused ? 406 : 200);
    }
}
