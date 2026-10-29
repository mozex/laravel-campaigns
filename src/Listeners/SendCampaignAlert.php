<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Events\CampaignFirstComplaintEvent;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Events\CampaignQueueStalledEvent;
use Mozex\Campaigns\Mail\CampaignAlertMail;
use Mozex\Campaigns\Sending\StopLoss;
use Throwable;

/**
 * The package's own alert: a mail to campaigns.alert_address (nobody when it
 * is empty) when a campaign paused itself (never for a person's own pause),
 * got its first spam report, or has emails waiting on a queue nobody works
 * (at most once every STALLED_HOURS hours, whichever campaign).
 *
 * Sent at once through the app's default mailer, never queued and never
 * through the campaigns mailer: a stalled queue would hold a queued alert,
 * and the marketing domain may be what the provider just refused. A send
 * that fails is reported, never thrown, so it can't fail what paused.
 */
class SendCampaignAlert
{
    /**
     * Hours between two alerts about a stalled queue.
     */
    public const STALLED_HOURS = 6;

    /**
     * The mark in campaigns.cache_store that holds the next stalled-queue
     * alert back.
     */
    public const STALLED_MARK = 'campaigns:alerts:stalled';

    public function paused(CampaignPausedEvent $event): void
    {
        if (! $event->automatic) {
            return;
        }

        $campaign = $event->campaign;

        $this->send(__('campaigns::mail.alert.paused_subject', ['app' => $this->app(), 'campaign' => $campaign->name]), [
            __('campaigns::mail.alert.paused', ['campaign' => $campaign->name, 'key' => $campaign->key]),
            (string) $event->reason,
            __('campaigns::mail.alert.paused_next'),
        ]);
    }

    public function firstComplaint(CampaignFirstComplaintEvent $event): void
    {
        $campaign = $event->campaign;
        $limits = StopLoss::thresholds($campaign);

        $this->send(__('campaigns::mail.alert.complaint_subject', ['app' => $this->app(), 'campaign' => $campaign->name]), [
            __('campaigns::mail.alert.complaint', [
                'campaign' => $campaign->name,
                'key' => $campaign->key,
                'count' => number_format((int) $limits['complaints']),
                'until' => number_format((int) $limits['complaints_until']),
                'rate' => (string) $limits['complaint_rate'],
            ]),
        ]);
    }

    public function stalled(CampaignQueueStalledEvent $event): void
    {
        if ($this->address() === null) {
            return;
        }

        $first = rescue(fn (): bool => Cache::store(config('campaigns.cache_store'))
            ->add(static::STALLED_MARK, true, now()->addHours(static::STALLED_HOURS)), false);

        if (! $first) {
            return;
        }

        $campaign = $event->campaign;

        $sent = $this->send(__('campaigns::mail.alert.stalled_subject', ['app' => $this->app()]), [
            __('campaigns::mail.alert.stalled', [
                'count' => number_format($event->stalledMessages),
                'campaign' => $campaign->name,
                'key' => $campaign->key,
                'queue' => (string) config('campaigns.queue.name'),
                'since' => $event->oldestClaimedAt->format('Y-m-d H:i T'),
            ]),
            __('campaigns::mail.alert.stalled_next', ['hours' => static::STALLED_HOURS]),
        ]);

        // A notice that never went out holds nothing back.
        if (! $sent) {
            rescue(fn (): bool => Cache::store(config('campaigns.cache_store'))->forget(static::STALLED_MARK));
        }
    }

    /**
     * Returns whether the mail went out.
     *
     * @param  list<string>  $lines
     */
    protected function send(string $subject, array $lines): bool
    {
        $address = $this->address();

        if ($address === null) {
            return false;
        }

        try {
            Mail::to($address)->send(new CampaignAlertMail($subject, $lines));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    protected function address(): ?string
    {
        $address = config('campaigns.alert_address');

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }

    protected function app(): string
    {
        return app(Campaigns::class)->name();
    }
}
