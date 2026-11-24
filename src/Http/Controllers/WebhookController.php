<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Contracts\Cache\Repository;
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
 * answers 404 without being built, and one that takes no webhooks 404 too;
 * one whose webhook key isn't set answers 500, unreported, so its service
 * retries until the key is set (EventBridge drops a 404 for good). A
 * 406 tells the service to stop retrying (a bad signature, a stale or
 * replayed request); a 500 makes it retry.
 */
class WebhookController
{
    public function __invoke(Request $request, string $driver, DriverManager $drivers, RecordFeedbackAction $record): Response
    {
        try {
            $configured = $drivers->configured($driver);
            $receiver = $configured === null ? null : DriverManager::ability($configured, ReceivesWebhooks::class);

            if ($receiver === null) {
                // Webhooks the driver's settings leave off (no signing key)
                // answer 500, so a service already posting retries until the
                // key is set, and are never reported: anyone can post here,
                // and the overview says the setting is missing. A 404 would
                // end EventBridge's retries.
                abort_if($configured === null || DriverManager::missingSetup($configured, ReceivesWebhooks::class) === null, 404);

                rescue(fn (): bool => static::cache()->put(static::refusedMark($driver), true, 86400), report: false);

                return response('', 500);
            }

            $verified = $receiver->verifyWebhook($request);
        } catch (CampaignsException $exception) {
            // The driver can't verify webhooks though its settings are there
            // (a creator that failed, a driver of the app's own). The route
            // takes anyone's posts, so it is reported once an hour, not on
            // every request, and answered so the service retries.
            if (static::cache()->add('campaigns:webhook-unverifiable:'.$driver, true, 3600)) {
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

    /**
     * The cache key that says the route refused a post for the driver in
     * the last day because its webhook key isn't set, which the overview
     * reads.
     */
    public static function refusedMark(string $driver): string
    {
        return 'campaigns:webhook-refused:'.$driver;
    }

    protected static function cache(): Repository
    {
        $store = config('campaigns.cache_store') ?: null;

        return Cache::store(is_string($store) ? $store : null);
    }
}
