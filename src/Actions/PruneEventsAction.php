<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\WebhookToken;
use Throwable;

/**
 * Deletes the provider's events older than campaigns.event_retention_days,
 * and the webhook tokens past the day their signature is accepted. A
 * finished campaign's report is frozen first, since it counts those events
 * (its machine opens and clicks, its clicks per link); a campaign still
 * sending or paused keeps its events until it completes or is cancelled,
 * since its report is still worked out live, and so does one whose freeze
 * failed. A test email's events go at the same age.
 */
class PruneEventsAction
{
    /**
     * How many rows one delete takes, so no statement holds a large lock.
     */
    public const CHUNK = 1000;

    public function __construct(protected FreezeReportAction $freeze) {}

    /**
     * @return array{frozen: int, events: int, tokens: int}
     */
    public function execute(): array
    {
        $cutoff = now()->subDays(max(1, (int) config('campaigns.event_retention_days', 180)));
        $frozen = 0;

        Campaign::query()
            ->whereIn('status', [CampaignStatus::Completed, CampaignStatus::Canceled])
            ->whereNull('report')
            ->whereHas('messages.events', fn (Builder $query): Builder => $query->where('occurred_at', '<', $cutoff))
            // By id, since each freeze takes its campaign out of the query.
            ->lazyById()
            ->each(function (Campaign $campaign) use (&$frozen): void {
                try {
                    $frozen += (int) $this->freeze->execute($campaign);
                } catch (Throwable $exception) {
                    // Its events stay until a later run freezes it.
                    report($exception);
                }
            });

        $events = $this->deleteInChunks(ProviderEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('message_id')
                ->orWhereHas('message.campaign', fn (Builder $query): Builder => $query
                    ->whereIn('status', [CampaignStatus::Completed, CampaignStatus::Canceled])
                    ->whereNotNull('report'))));

        $tokens = $this->deleteInChunks(WebhookToken::query()
            ->where('created_at', '<', now()->subHours(WebhookToken::KEEP_HOURS)));

        return ['frozen' => $frozen, 'events' => $events, 'tokens' => $tokens];
    }

    /**
     * Deletes what a query matches a chunk of ids at a time (PostgreSQL has
     * no DELETE ... LIMIT).
     *
     * @param  Builder<ProviderEvent>|Builder<WebhookToken>  $query
     */
    protected function deleteInChunks(Builder $query): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(static::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : $query->getModel()->newQuery()->whereKey($ids->all())->delete();
        } while ($ids->count() === static::CHUNK);

        return $deleted;
    }
}
