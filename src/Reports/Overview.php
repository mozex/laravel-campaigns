<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Placeholders;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Engagement;
use Mozex\Campaigns\Support\Sender;
use Mozex\Campaigns\Support\TestAddresses;

/**
 * What an agent reads first: how sending is set up and what is switched
 * off, the lists with their counts, the campaigns running or waiting, and
 * the placeholders an email may hold with this app's values.
 */
class Overview
{
    /**
     * At most this many lists and campaigns, and this much of a pause's
     * reason (the status tool has it whole), so the answer stays under
     * CampaignsTool::RESULT_LIMIT with every column at its longest.
     */
    public const LIMIT = 25;

    public const PAUSE_REASON_LENGTH = 300;

    /** The statuses of a campaign that is running or waiting to. */
    public const OPEN_STATUSES = [
        CampaignStatus::Starting,
        CampaignStatus::Scheduled,
        CampaignStatus::Sending,
        CampaignStatus::Paused,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'app' => app(Campaigns::class)->name(),
            'package_version' => static::version(),
            'sending' => static::sending(),
            'lists' => static::lists(),
            'lists_total' => CampaignList::query()->count(),
            'campaigns' => static::campaigns(),
            'campaigns_total' => Campaign::query()->whereIn('status', static::OPEN_STATUSES)->count(),
            'placeholders' => static::placeholders(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function sending(): array
    {
        $notes = [];

        try {
            $domain = Sender::domain();
        } catch (CampaignsException $exception) {
            $domain = null;
            $notes[] = $exception->getMessage();
        }

        $enabled = (bool) config('campaigns.enabled');
        $provider = config('campaigns.provider');
        $logsReadable = filled(config('campaigns.mailgun.api_key'));
        $testAddresses = TestAddresses::configured();

        if (! $enabled) {
            $notes[] = 'Sending is switched off (campaigns.enabled): campaigns can be written, previewed and given an audience, but none can start, and no test or confirmation email goes out.';
        }

        if ($provider === 'mailgun' && ! $logsReadable) {
            $notes[] = 'campaigns.mailgun.api_key isn\'t set, so nothing is reconciled from Mailgun\'s logs and campaigns:reconcile fails every run.';
        }

        if ($testAddresses === []) {
            $notes[] = 'No test addresses are set (campaigns.test_addresses), so no test email can go out.';
        }

        return [
            'enabled' => $enabled,
            'provider' => is_string($provider) ? $provider : get_debug_type($provider),
            'mailer' => Sender::mailer(),
            'from_address' => Sender::address(),
            'from_name' => Sender::name(),
            'reply_to' => Sender::replyTo(),
            'domain' => $domain,
            'provider_logs_readable' => $logsReadable,
            'test_addresses' => $testAddresses,
            'spam_score_check' => (bool) config('campaigns.checks.spam_score'),
            'notes' => $notes,
        ];
    }

    /**
     * Each list with its subscriptions by status, how many of its
     * subscribed people may be mailed (not opted out, blocked or sunset),
     * and its subscribed people by engagement tier.
     *
     * @return list<array<string, mixed>>
     */
    protected static function lists(): array
    {
        $lists = CampaignList::query()->orderBy('key')->limit(static::LIMIT)->get();

        if ($lists->isEmpty()) {
            return [];
        }

        $ids = $lists->modelKeys();

        $statuses = Subscription::query()
            ->whereIn('list_id', $ids)
            ->groupBy('list_id', 'status')
            ->selectRaw('list_id, status, count(*) as total')
            ->toBase()
            ->get();

        $mailable = static::subscribedCounts($ids, fn (Builder $query): Builder => $query->mailable());

        $tiers = [];

        foreach (EngagementTier::cases() as $tier) {
            $tiers[$tier->value] = static::subscribedCounts($ids, fn (Builder $query): Builder => Engagement::whereTier($query, $tier));
        }

        return $lists->map(function (CampaignList $list) use ($statuses, $mailable, $tiers): array {
            $counts = [];

            foreach (SubscriptionStatus::cases() as $status) {
                $counts[$status->value] = (int) ($statuses->first(fn (object $row): bool => (int) $row->list_id === $list->id && $row->status === $status->value)->total ?? 0);
            }

            return [
                'key' => $list->key,
                'name' => $list->name,
                'kind' => $list->kind->value,
                'double_opt_in' => $list->double_opt_in,
                ...$counts,
                'mailable' => (int) ($mailable[$list->id] ?? 0),
                'tiers' => array_map(fn (Collection $tierCounts): int => (int) ($tierCounts[$list->id] ?? 0), $tiers),
            ];
        })->all();
    }

    /**
     * The subscribed people on each list whose subscriber the constraint
     * keeps, by list id.
     *
     * @param  list<int>  $ids
     * @param  callable(Builder<Subscriber>): Builder<Subscriber>  $constraint
     * @return Collection<int, int>
     */
    protected static function subscribedCounts(array $ids, callable $constraint): Collection
    {
        /** @var Collection<int, int> */
        return Subscription::query()
            ->whereIn('list_id', $ids)
            ->where('status', SubscriptionStatus::Subscribed)
            ->whereHas('subscriber', $constraint)
            ->groupBy('list_id')
            ->selectRaw('list_id, count(*) as total')
            ->toBase()
            ->pluck('total', 'list_id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function campaigns(): array
    {
        return Campaign::query()
            ->whereIn('status', static::OPEN_STATUSES)
            ->orderByDesc('id')
            ->limit(static::LIMIT)
            ->get()
            ->map(fn (Campaign $campaign): array => [
                'uuid' => $campaign->uuid,
                'key' => $campaign->key,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'recipient_count' => $campaign->recipient_count,
                'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                'started_at' => $campaign->started_at?->toIso8601String(),
                'paused_at' => $campaign->paused_at?->toIso8601String(),
                'pause_reason' => $campaign->pause_reason === null ? null : Str::limit($campaign->pause_reason, static::PAUSE_REASON_LENGTH),
            ])
            ->all();
    }

    /**
     * @return list<array{name: string, description: string, value: ?string, in_subject: bool}>
     */
    protected static function placeholders(): array
    {
        $values = [
            'postal_address' => config('campaigns.postal_address'),
            'privacy_url' => config('campaigns.privacy_url'),
        ];

        return array_map(fn (string $name): array => [
            'name' => $name,
            'description' => Placeholders::DESCRIPTIONS[$name],
            'value' => is_string($values[$name] ?? null) && $values[$name] !== '' ? $values[$name] : null,
            'in_subject' => in_array($name, Placeholders::PERSONAL, true),
        ], Placeholders::ALL);
    }

    protected static function version(): ?string
    {
        return InstalledVersions::isInstalled('mozex/laravel-campaigns')
            ? InstalledVersions::getPrettyVersion('mozex/laravel-campaigns')
            : null;
    }
}
