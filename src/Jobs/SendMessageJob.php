<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Actions\PauseCampaignAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Sending\MessageComposer;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Support\Token;
use Throwable;

/**
 * Sends one campaign message the pump claimed. It carries the message's id
 * and the claim's: it takes the message from queued to sending by a
 * conditional update on both, and sends nothing unless that changed the row,
 * so a duplicate job, or one left from an older claim, does nothing. The
 * links' token is made here and only its hash stored, in that same update,
 * so the raw token never sits in the queue.
 */
class SendMessageJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * A claim older than this is stale: its job waited out a stopped worker,
     * and sending it now would go past the pace and the sending hours. It
     * goes back to pending for a fresh claim instead.
     */
    public const CLAIM_EXPIRES = 900;

    /**
     * Unclear sends within UNCLEAR_MINUTES that pause a campaign (unclear()).
     */
    public const UNCLEAR_LIMIT = 10;

    public const UNCLEAR_MINUTES = 5;

    /**
     * A retry could send the same message twice.
     */
    public int $tries = 1;

    /**
     * Above the 30 seconds a campaigns Mailgun mailer is given by default.
     */
    public int $timeout = 60;

    public function __construct(public int $messageId, public string $claimId)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function handle(CampaignProvider $provider, MessageComposer $composer, PauseCampaignAction $pause): void
    {
        $token = Token::generate();

        if (! $this->take($token)) {
            return;
        }

        $message = CampaignMessage::query()->with(['campaign.frozenRevision', 'subscriber'])->find($this->messageId);

        if ($message === null) {
            return;
        }

        if (! $this->mayStillBeSent($message)) {
            $this->finish(MessageStatus::Skipped, ['token_hash' => null]);

            return;
        }

        try {
            $outgoing = $composer->outgoing($message, $token);
        } catch (CampaignsException) {
            // Its address was forgotten, or its campaign has no email to send.
            $this->finish(MessageStatus::Skipped, ['token_hash' => null]);

            return;
        }

        try {
            $receipt = $provider->send($outgoing);
        } catch (SendFailed $failed) {
            $this->refused($failed, $message, $pause);

            return;
        } catch (Throwable $exception) {
            // A provider of the app's own broke its contract: it may have the
            // message, so it is never sent again blindly.
            $this->unclear($message, $pause);

            throw $exception;
        }

        $this->sent($receipt, $message);
    }

    /**
     * Takes the message from queued to sending, only while its campaign is
     * sending, sending is switched on and the claim is fresh. A message still
     * queued under this claim that can't be taken (its campaign paused or
     * cancelled, sending switched off, the claim stale) goes back to pending,
     * with nothing sent.
     */
    protected function take(string $token): bool
    {
        $now = now();

        $taken = config('campaigns.enabled') && $this->ours(MessageStatus::Queued)
            ->where('claimed_at', '>=', $now->copy()->subSeconds(self::CLAIM_EXPIRES))
            ->whereHas('campaign', fn (Builder $query): Builder => $query->where('status', CampaignStatus::Sending))
            ->update([
                'status' => MessageStatus::Sending,
                'sending_at' => $now,
                'token_hash' => Token::hash($token),
                'updated_at' => $now,
            ]) === 1;

        if (! $taken) {
            $this->backToPending(MessageStatus::Queued);
        }

        return $taken;
    }

    /**
     * The send-time checks: the person still holds the address the message
     * was frozen for (a deletion or an address change since leaves it), may
     * still be mailed (not opted out, blocked or sunset), and is still
     * subscribed on one of the lists it qualified through.
     */
    protected function mayStillBeSent(CampaignMessage $message): bool
    {
        $subscriber = $message->subscriber;

        if (! $subscriber instanceof Subscriber || $message->email === null || $subscriber->email !== $message->email) {
            return false;
        }

        if (! $subscriber->isMailable() || $message->list_ids === null || $message->list_ids === []) {
            return false;
        }

        return Subscription::query()
            ->where('subscriber_id', $subscriber->id)
            ->whereIn('list_id', $message->list_ids)
            ->where('status', SubscriptionStatus::Subscribed)
            ->exists();
    }

    /**
     * The provider took it. A row already marked unknown counts too: a job
     * taken for dead (failed()) may still have finished its send.
     */
    protected function sent(SendReceipt $receipt, CampaignMessage $message): void
    {
        $now = now();

        $providerMessageId = mb_substr($receipt->providerMessageId, 0, 255);

        $moved = $this->ours(MessageStatus::Sending, MessageStatus::Unknown)->update([
            'status' => MessageStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'sent_at' => $now,
            'failure_reason' => null,
            'failure_code' => null,
            'updated_at' => $now,
        ]);

        // The provider's events can land before this does (delivered, or a
        // bounce, applied while the answer was on its way back): the row has
        // moved on, and keeps its status, but gains the id the logs are read by.
        if ($moved === 0) {
            $this->ours(MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Failed)
                ->whereNull('provider_message_id')
                ->update(['provider_message_id' => $providerMessageId, 'updated_at' => $now]);
        }

        // The address's first campaign email, which the sunset counts from.
        // Skipped once set: on MySQL the update locks the row before its
        // check, and would wait on a freeze holding its recipients' rows.
        if ($message->subscriber?->first_mailed_at === null) {
            Subscriber::query()
                ->whereKey($message->subscriber_id)
                ->whereNull('first_mailed_at')
                ->update(['first_mailed_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * What the provider's refusal means for the message: taken nothing,
     * back to pending, the campaign's rate halved for ten minutes; the domain
     * refused, back to pending and every campaign on the domain paused;
     * unclear, unknown until its logs are read.
     */
    protected function refused(SendFailed $failed, CampaignMessage $message, PauseCampaignAction $pause): void
    {
        match ($failed->failure) {
            SendFailure::NotAccepted => $this->notAccepted($message),
            SendFailure::DomainRefused => $this->domainRefused($failed, $message, $pause),
            SendFailure::Unclear => $this->unclear($message, $pause, $failed->status),
        };
    }

    /**
     * An unclear send is never sent again, and the reconciler fails it once
     * the provider's logs show nothing, so a run of them (a lost connection,
     * a gateway answering 504) would spend the campaign's audience: each one
     * halves the rate for ten minutes, and UNCLEAR_LIMIT of them within
     * UNCLEAR_MINUTES pause the campaign until someone looks.
     */
    protected function unclear(CampaignMessage $message, PauseCampaignAction $pause, ?int $status = null): void
    {
        $this->finish(MessageStatus::Unknown, [
            'failure_reason' => SendFailure::Unclear->value,
            'failure_code' => $status,
        ]);

        Campaign::query()->whereKey($message->campaign_id)->update([
            'rate_halved_until' => now()->addMinutes(10),
            'updated_at' => now(),
        ]);

        $recent = CampaignMessage::query()
            ->where('campaign_id', $message->campaign_id)
            ->where('status', MessageStatus::Unknown)
            ->where('failure_reason', SendFailure::Unclear->value)
            ->where('sending_at', '>=', now()->subMinutes(static::UNCLEAR_MINUTES))
            ->count();

        // Found on its own, so the event's listeners may read its relations.
        $campaign = $recent >= static::UNCLEAR_LIMIT ? Campaign::query()->find($message->campaign_id) : null;

        if ($campaign?->status !== CampaignStatus::Sending) {
            return;
        }

        try {
            $pause->execute($campaign, "{$recent} sends in the last ".static::UNCLEAR_MINUTES.' minutes ended without a clear answer from the provider (a lost connection or a gateway error, say). None of them is sent again; check the provider is reachable before resuming.', automatic: true);
        } catch (CampaignsException) {
            // Another job paused it a moment ago.
        } catch (Throwable $exception) {
            // A listener that failed: the campaign is paused all the same.
            report($exception);
        }
    }

    protected function notAccepted(CampaignMessage $message): void
    {
        $this->backToPending(MessageStatus::Sending);

        Campaign::query()->whereKey($message->campaign_id)->update([
            'rate_halved_until' => now()->addMinutes(10),
            'updated_at' => now(),
        ]);
    }

    protected function domainRefused(SendFailed $failed, CampaignMessage $message, PauseCampaignAction $pause): void
    {
        $this->backToPending(MessageStatus::Sending);

        $domain = $message->campaign->sendingDomain();
        // Scrubbed and cut, since an app's own provider may not have, and the
        // pause refuses a reason that is too long or not text.
        $reason = SendFailed::scrub("The provider refused the sending domain {$domain}: {$failed->getMessage()}", 1000);

        $ids = Campaign::query()
            ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Scheduled])
            ->get(['id', 'from_email'])
            ->filter(fn (Campaign $campaign): bool => $campaign->sendingDomain() === $domain)
            ->modelKeys();

        foreach ($ids as $id) {
            // Each found on its own, so the event's listeners may read its
            // relations under strict models.
            $campaign = Campaign::query()->find($id);

            if ($campaign === null) {
                continue;
            }

            try {
                $pause->execute($campaign, $reason, automatic: true);
            } catch (CampaignsException) {
                // It completed or was cancelled meanwhile: nothing to pause.
            } catch (Throwable $exception) {
                // A listener that failed: the domain's other campaigns still pause.
                report($exception);
            }
        }
    }

    /**
     * A job that died (a timeout, a lost worker) after its claim: still
     * queued, nothing went, so it waits again; sending, the provider may have
     * it, so it is unknown until its logs are read.
     */
    public function failed(?Throwable $exception = null): void
    {
        $this->backToPending(MessageStatus::Queued);

        $this->ours(MessageStatus::Sending)->update([
            'status' => MessageStatus::Unknown,
            'failure_reason' => SendFailure::Unclear->value,
            'updated_at' => now(),
        ]);
    }

    /**
     * Back to pending with its claim and token cleared, so the pace counts
     * it as never sent and the next claim starts afresh.
     */
    protected function backToPending(MessageStatus $from): void
    {
        $this->ours($from)->update([
            'status' => MessageStatus::Pending,
            ...CampaignMessage::UNCLAIMED,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function finish(MessageStatus $status, array $values = []): void
    {
        $this->ours(MessageStatus::Sending)->update([
            'status' => $status,
            ...$values,
            'updated_at' => now(),
        ]);
    }

    /**
     * This job's message, under this job's claim, in one of these statuses.
     *
     * @return Builder<CampaignMessage>
     */
    protected function ours(MessageStatus ...$statuses): Builder
    {
        return CampaignMessage::query()
            ->whereKey($this->messageId)
            ->where('claim_id', $this->claimId)
            ->whereIn('status', $statuses);
    }
}
