<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mail\ConfirmSubscriptionMail;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\PageLocale;
use Mozex\Campaigns\Support\Token;
use Throwable;

/**
 * Sends a double opt-in's confirmation email. It carries only the
 * subscription's id: the link's token is made here and only its hash
 * stored, so the raw token never sits in the queue, a failed job or an
 * error report.
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

    public function handle(): void
    {
        if (! config('campaigns.enabled')) {
            return;
        }

        $subscription = Subscription::query()->with(['subscriber', 'list'])->find($this->subscriptionId);

        if ($subscription === null || $subscription->status !== SubscriptionStatus::Pending || $subscription->subscriber->blocked_at !== null) {
            return;
        }

        $token = Token::generate();
        $hash = Token::hash($token);
        $previous = $subscription->confirm_token_hash;

        try {
            // Resolved first, so a mailer that isn't configured fails before
            // the earlier email's link is replaced.
            $mailer = Mail::mailer(config('campaigns.mail.mailer'));

            $stored = Subscription::query()
                ->whereKey($subscription->id)
                ->where('status', SubscriptionStatus::Pending)
                ->update(['confirm_token_hash' => $hash, 'updated_at' => now()]);

            if ($stored === 0) {
                return;
            }

            $mailer->to($subscription->subscriber->email)
                ->locale(PageLocale::pick($subscription->subscriber->locale))
                ->send(new ConfirmSubscriptionMail($subscription->list->name, Links::confirm($token)));
        } catch (Throwable $exception) {
            // Nothing went out: the earlier email's link works again, and the
            // address may be asked again today.
            Subscription::query()
                ->whereKey($subscription->id)
                ->where('confirm_token_hash', $hash)
                ->update(['confirm_token_hash' => $previous, 'updated_at' => now()]);

            Subscriber::query()
                ->whereKey($subscription->subscriber_id)
                ->update(['confirmation_sent_at' => null, 'updated_at' => now()]);

            // A mailer's refusal may quote the address ("550 <ann@...>:
            // Recipient rejected"), so it fails the job scrubbed and unchained,
            // never as itself, which would reach failed_jobs and error reports.
            throw new CampaignsException(SendFailed::scrub('The confirmation email could not be sent: '.$exception->getMessage(), 1000));
        }
    }
}
