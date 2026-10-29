<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Audiences;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\MailboxProvider;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Engagement;

/**
 * The one query behind a campaign's audience, shared by the preview, the
 * start's count and the freeze, so the number an agent confirms is the
 * number frozen. Subscribers are selected through subqueries, never a join
 * to their subscriptions, so a person on two target lists is one row.
 */
class CampaignAudience
{
    /**
     * The columns each recipient is read with: what a message records and
     * what places it in the send order.
     */
    protected const COLUMNS = ['id', 'email', 'locale', ...Engagement::SIGNALS];

    /**
     * Addresses the preview reads at a time.
     */
    protected const ADDRESS_CHUNK = 5000;

    /**
     * @param  list<int>  $listIds  the target lists
     */
    public function __construct(public array $listIds, public AudienceDefinition $definition) {}

    public static function for(Campaign $campaign): self
    {
        return new self(
            array_map('intval', $campaign->target_lists ?? []),
            AudienceDefinition::fromStored($campaign->audience),
        );
    }

    /**
     * Everyone the campaign targets: subscribed on a target list (a pending
     * double opt-in, or a record that can't be on its dynamic list now,
     * isn't) and in the audience, whether or not they may be mailed.
     *
     * @return Builder<Subscriber>
     *
     * @throws CampaignsException when it has no target lists, or a part of the audience no longer exists
     */
    public function targeted(): Builder
    {
        if ($this->listIds === []) {
            throw CampaignsException::noTargetLists();
        }

        $query = Subscriber::query()->whereHas('subscriptions', fn (Builder $query): Builder => $query
            ->whereIn('list_id', $this->listIds)
            ->where('status', SubscriptionStatus::Subscribed));

        return $this->definition->apply($query);
    }

    /**
     * The targeted subscribers who may be mailed: not opted out, blocked or
     * sunset.
     *
     * @return Builder<Subscriber>
     */
    public function recipients(): Builder
    {
        return $this->targeted()->mailable();
    }

    /**
     * Calls the callback with each recipient, in id order, and the target
     * lists it is subscribed on, reading a chunk at a time. Returns how many
     * there were.
     *
     * @param  Closure(Subscriber, list<int>): void  $callback
     */
    public function each(Closure $callback, int $chunk = 500): int
    {
        $count = 0;

        $query = $this->recipients()
            ->select(static::COLUMNS)
            ->with(['subscriptions' => fn (Relation $query): Relation => $query
                ->select(['id', 'subscriber_id', 'list_id'])
                ->whereIn('list_id', $this->listIds)
                ->where('status', SubscriptionStatus::Subscribed)]);

        foreach ($query->lazyById($chunk) as $subscriber) {
            $lists = $subscriber->subscriptions->map(fn (Subscription $subscription): int => $subscription->list_id)->sort()->values()->all();

            // Taken off the lists between reading the chunk and its
            // subscriptions (READ COMMITTED on PostgreSQL): not a recipient.
            if ($lists === []) {
                continue;
            }

            $callback($subscriber, $lists);
            $count++;
        }

        return $count;
    }

    /**
     * How many the campaign would reach and who they are, in counts only.
     * The database counts each breakdown, and only the addresses are read,
     * for their mailbox providers: reading every recipient as a model, as
     * the freeze does, outlasts a request's time limit once an audience
     * reaches hundreds of thousands.
     */
    public function preview(): AudiencePreview
    {
        $lists = [];

        foreach (CampaignList::query()->whereIn('id', $this->listIds)->pluck('key', 'id') as $id => $key) {
            $lists[(string) $key] = $this->recipients()->whereHas('subscriptions', fn (Builder $query): Builder => $query
                ->where('list_id', $id)
                ->where('status', SubscriptionStatus::Subscribed))->count();
        }

        $locales = [];

        foreach ($this->recipients()->toBase()->select('locale')->selectRaw('count(*) as total')->groupBy('locale')->get() as $row) {
            $locale = $row->locale ?? 'none';
            $locales[$locale] = ($locales[$locale] ?? 0) + (int) $row->total;
        }

        $tiers = [];

        foreach (EngagementTier::cases() as $tier) {
            $tiers[$tier->value] = Engagement::whereTier($this->recipients(), $tier)->count();
        }

        $providers = array_fill_keys(array_column(MailboxProvider::cases(), 'value'), 0);

        foreach ($this->recipients()->toBase()->select(['id', 'email'])->lazyById(static::ADDRESS_CHUNK, 'id') as $row) {
            $providers[MailboxProvider::fromEmail((string) $row->email)->value]++;
        }

        ksort($lists);
        ksort($locales);

        return new AudiencePreview(
            recipients: array_sum($providers),
            targeted: $this->targeted()->count(),
            dropped: [
                'blocked' => $this->targeted()->whereNotNull('blocked_at')->count(),
                'opted_out' => $this->targeted()->whereNull('blocked_at')->whereNotNull('opted_out_at')->count(),
                'sunset' => Engagement::whereSunset($this->targeted()->whereNull('blocked_at')->whereNull('opted_out_at'), true)->count(),
            ],
            byList: $lists,
            byLocale: $locales,
            byTier: $tiers,
            byProvider: $providers,
            unmatchedEmails: $this->definition->unmatchedEmails,
        );
    }

    /**
     * The languages the recipients read, for the pre-send checks: those with
     * none set get the default language.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->recipients()
            ->whereNotNull('locale')
            ->distinct()
            ->pluck('locale')
            ->filter(fn (mixed $locale): bool => is_string($locale) && Locales::isValid($locale))
            ->sort()
            ->values()
            ->all();
    }
}
