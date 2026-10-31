<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Composer\InstalledVersions;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Placeholders;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Support\Sender;
use Mozex\Campaigns\Support\ShownText;
use Mozex\Campaigns\Support\TestAddresses;

/**
 * What an agent reads first: how sending is set up and what is switched
 * off, the sending domain's standing (DomainSummary), the lists with their
 * counts, the campaigns running or waiting, and the placeholders an email
 * may hold with this app's values.
 */
class Overview
{
    /**
     * At most this many lists and campaigns, this much of a list's or a
     * campaign's name, and this much of a pause's reason (the list and
     * status tools have them whole), so the answer stays under
     * CampaignsTool::RESULT_LIMIT, counted as JSON, with every column at its
     * longest and every free character a quote.
     */
    public const LIMIT = 25;

    public const NAME_LENGTH = 100;

    public const PAUSE_REASON_LENGTH = 150;

    /** The statuses of a campaign that is running or waiting to. */
    public const OPEN_STATUSES = [
        CampaignStatus::Starting,
        CampaignStatus::Scheduled,
        CampaignStatus::Sending,
        CampaignStatus::Paused,
    ];

    /**
     * What a driver without each ability can't do, by the ability's name
     * (the domain summary says what a driver without counts can't).
     */
    protected const MISSING = [
        'webhooks' => 'takes no webhooks, so what happens to an email after its service takes it is learnt only from its logs, if it reads any',
        'events' => 'reads no logs, so nothing a webhook missed is replayed, and an email whose send ended unclear stays unknown, never sent again',
        'suppressions' => 'reads no suppression lists, so campaigns:import-provider-suppressions and unblock_contact can\'t ask its service which addresses it refuses',
    ];

    /**
     * What a driver that reads nothing back means for a campaign it sends
     * (it has neither webhooks nor logs), or null for one that does: the
     * overview, the start and the resume say it.
     */
    public static function readsNothingBack(): ?string
    {
        $drivers = app(DriverManager::class);
        $name = $drivers->getDefaultDriver();

        try {
            $abilities = DriverManager::abilities($drivers->active());
        } catch (CampaignsException) {
            return null;
        }

        if (in_array('webhooks', $abilities, true) || in_array('events', $abilities, true)) {
            return null;
        }

        return CampaignsException::driverLacks($name, 'reads nothing back from its service (no webhooks, no logs), so no bounce, spam report or unsubscribe made at the service reaches the package (its own unsubscribe page and one-click still work): the stop-loss sees only what is refused while sending, only a refusal naming a bad mailbox blocks an address, and emails end as sent, never delivered')->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $sending = static::sending();
        $domain = null;

        if (is_string($sending['domain'])) {
            ['summary' => $domain, 'notes' => $notes] = DomainSummary::for($sending['domain']);
            $sending['notes'] = [...$sending['notes'], ...$notes];
        }

        return [
            'app' => app(Campaigns::class)->name(),
            'package_version' => static::version(),
            'sending' => $sending,
            'domain' => $domain,
            'lists' => static::lists(),
            'lists_total' => CampaignList::query()->active()->count(),
            'campaigns' => static::campaigns(),
            'campaigns_total' => Campaign::query()->whereIn('status', static::OPEN_STATUSES)->count(),
            'placeholders' => static::placeholders(),
        ];
    }

    /**
     * @return array{enabled: bool, driver: array{name: string, abilities: list<string>}, mailer: string, from_address: ?string, from_name: ?string, reply_to: ?string, domain: ?string, test_addresses: list<string>, spam_score_check: bool, notes: list<string>}
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
        $drivers = app(DriverManager::class);
        $name = $drivers->getDefaultDriver();
        $testAddresses = TestAddresses::configured();

        try {
            $abilities = DriverManager::abilities($drivers->active());
            $nothingBack = static::readsNothingBack();
            $missing = static::MISSING;

            // One warning for a driver with neither webhooks nor logs, in
            // place of the two it would hold.
            if ($nothingBack !== null) {
                $notes[] = $nothingBack;
                unset($missing['webhooks'], $missing['events']);
            }

            foreach ($missing as $ability => $lack) {
                if (! in_array($ability, $abilities, true)) {
                    $notes[] = CampaignsException::driverLacks($name, $lack)->getMessage();
                }
            }
        } catch (CampaignsException $exception) {
            $abilities = [];
            $notes[] = $exception->getMessage();
        }

        if (! $enabled) {
            $notes[] = 'Sending is switched off (campaigns.enabled): campaigns can be written, previewed and given an audience, but none can start, and no test or confirmation email goes out.';
        }

        if ($testAddresses === []) {
            $notes[] = 'No test addresses are set (campaigns.test_addresses), so no test email can go out.';
        }

        return [
            'enabled' => $enabled,
            'driver' => ['name' => (string) ShownText::of($name, 50), 'abilities' => $abilities],
            'mailer' => Sender::mailer(),
            'from_address' => Sender::address(),
            'from_name' => Sender::name(),
            'reply_to' => Sender::replyTo(),
            'domain' => $domain,
            'test_addresses' => $testAddresses,
            'spam_score_check' => (bool) config('campaigns.checks.spam_score'),
            'notes' => $notes,
        ];
    }

    /**
     * The lists in use with their counts; archived ones only through the
     * list tool.
     *
     * @return list<array<string, mixed>>
     */
    protected static function lists(): array
    {
        return ListSummary::rows(CampaignList::query()->active()->orderBy('key')->limit(static::LIMIT)->get(), nameLength: static::NAME_LENGTH);
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
                'name' => ShownText::of($campaign->name, static::NAME_LENGTH),
                'status' => $campaign->status->value,
                'recipient_count' => $campaign->recipient_count,
                'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                'started_at' => $campaign->started_at?->toIso8601String(),
                'paused_at' => $campaign->paused_at?->toIso8601String(),
                'pause_reason' => ShownText::of($campaign->pause_reason, static::PAUSE_REASON_LENGTH),
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
