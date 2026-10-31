<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mail\ConfirmSubscriptionMail;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\PageLocale;
use Mozex\Campaigns\Support\Token;
use Throwable;

/**
 * Sends a double opt-in's confirmation email through the active driver, as
 * every email the package sends goes. It carries only the subscription's
 * id: the link's token is made here and only its hash stored, so the raw
 * token never sits in the queue, a failed job or an error report.
 */
class SendConfirmationJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A retry would send a second email with a second link. A send that
     * fails gives the address its day back instead (see handle()).
     */
    public int $tries = 1;

    public function __construct(public int $subscriptionId)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function handle(DriverManager $drivers): void
    {
        $subscription = Subscription::query()->with(['subscriber', 'list'])->find($this->subscriptionId);

        // Switched off after the action queued it: nothing goes out, so the
        // address may be asked again today.
        if (! config('campaigns.enabled')) {
            if ($subscription !== null) {
                Subscriber::query()
                    ->whereKey($subscription->subscriber_id)
                    ->update(['confirmation_sent_at' => null, 'updated_at' => now()]);
            }

            return;
        }

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Pending || $subscription->subscriber->blocked_at !== null) {
            return;
        }

        $token = Token::generate();
        $hash = Token::hash($token);
        $previous = $subscription->confirm_token_hash;

        try {
            // Built first, so a driver that isn't configured fails before
            // the earlier email's link is replaced.
            $driver = $drivers->active();

            // Only while the person still holds the address read above: an
            // address change voids waiting links, and a link stored after it
            // would go to the old address and confirm the new one.
            $stored = Subscription::query()
                ->whereKey($subscription->id)
                ->where('subscriber_id', $subscription->subscriber_id)
                ->where('status', SubscriptionStatus::Pending)
                ->whereHas('subscriber', fn (Builder $query): Builder => $query->where('email', $subscription->subscriber->email))
                ->update(['confirm_token_hash' => $hash, 'updated_at' => now()]);

            if ($stored === 0) {
                return;
            }

            $driver->send((new ConfirmSubscriptionMail($subscription->list->name, Links::confirm($token)))->outgoing(
                $subscription->subscriber->email,
                PageLocale::pick($subscription->subscriber->locale),
            ));
        } catch (Throwable $exception) {
            // Nothing went out, or the driver can't tell (an unclear send): the
            // earlier email's link works again, and the address may be asked
            // again today, since a second email is better than none.
            Subscription::query()
                ->whereKey($subscription->id)
                ->where('confirm_token_hash', $hash)
                ->update(['confirm_token_hash' => $previous, 'updated_at' => now()]);

            Subscriber::query()
                ->whereKey($subscription->subscriber_id)
                ->update(['confirmation_sent_at' => null, 'updated_at' => now()]);

            // A service's refusal may quote the address ("550 <ann@...>:
            // Recipient rejected"), so it fails the job scrubbed and unchained,
            // never as itself, which would reach failed_jobs and error reports.
            throw new CampaignsException(SendFailed::scrub('The confirmation email could not be sent: '.mb_scrub($exception->getMessage(), 'UTF-8'), 1000));
        }
    }
}
