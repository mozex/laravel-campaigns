<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Audiences;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Engagement;
use Mozex\Campaigns\Support\Keys;
use Throwable;

/**
 * The built-in filters over the package's own subscriber data, for choosing
 * an audience and for listing subscribers. They come from and go back to a
 * plain array, so an agent can send them and a saved audience can store
 * them. Filters combine with AND; the values given for one filter combine
 * with OR. A filter left out, or given no values, doesn't narrow anything.
 *
 * Compare a custom field with a value of the type it was stored as: SQLite
 * never matches the text "5" with the number 5. Locales and countries match
 * case-insensitively on MySQL, as stored on the others.
 *
 * They only target. Whether an address may be mailed at all (opted out,
 * blocked, sunset) is Subscriber::scopeMailable(), applied apart, so a
 * preview can say how many each reason dropped.
 */
readonly class SubscriberFilters
{
    /**
     * The keys fromArray() reads.
     */
    public const KEYS = ['lists', 'list_status', 'locales', 'countries', 'tiers', 'opted_out', 'blocked', 'sunset', 'custom_fields', 'dates', 'campaigns'];

    /**
     * What a subscriber did with an earlier campaign (by its key). Received
     * means the provider accepted the email and it didn't fail; the "not"
     * outcomes other than not_received need it received, so someone the
     * campaign never reached hasn't "not opened" it.
     */
    public const OUTCOMES = ['received', 'not_received', 'opened', 'not_opened', 'clicked', 'not_clicked'];

    /**
     * A custom field's name: letters, digits and underscores, never a digit
     * first. A dot or an arrow would reach into a nested value, and
     * PostgreSQL reads a name of digits as an array index.
     */
    public const CUSTOM_FIELD_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /**
     * The most values one filter takes: each is a binding, and a list past
     * a database's binding limit (65,535 on PostgreSQL) fails the query
     * rather than the filter.
     */
    public const MAX_VALUES = 100;

    /**
     * The dates a "dates" filter can bound, and their columns.
     */
    public const DATE_FIELDS = [
        'created' => 'created_at',
        'last_opened' => 'last_opened_at',
        'last_clicked' => 'last_clicked_at',
        'last_active' => 'last_active_at',
        'first_mailed' => 'first_mailed_at',
        'opted_out' => 'opted_out_at',
        'blocked' => 'blocked_at',
    ];

    /**
     * @param  list<string>  $lists  On any of these lists (by key), with the status $listStatus.
     * @param  SubscriptionStatus  $listStatus  Subscribed by default: a pending double opt-in, or a record that can't be on its dynamic list now, isn't on the list.
     * @param  list<?string>  $locales  Any of these locales; null matches a subscriber with none.
     * @param  list<?string>  $countries  Any of these two-letter countries; null matches a subscriber with none.
     * @param  list<EngagementTier>  $tiers
     * @param  array<string, string|int|bool|null>  $customFields  Each named custom field equal to its value; null matches a field that is missing or null.
     * @param  list<array{field: key-of<self::DATE_FIELDS>, after: ?CarbonImmutable, before: ?CarbonImmutable}>  $dates  Each date on or after "after" and before "before".
     * @param  list<array{campaign: string, outcome: value-of<self::OUTCOMES>}>  $campaigns  Each outcome of each campaign.
     */
    public function __construct(
        public array $lists = [],
        public SubscriptionStatus $listStatus = SubscriptionStatus::Subscribed,
        public array $locales = [],
        public array $countries = [],
        public array $tiers = [],
        public ?bool $optedOut = null,
        public ?bool $blocked = null,
        public ?bool $sunset = null,
        public array $customFields = [],
        public array $dates = [],
        public array $campaigns = [],
    ) {}

    /**
     * The filters from an array keyed by KEYS, as an agent sends them or
     * toArray() stored them.
     *
     * @param  array<array-key, mixed>  $filters
     *
     * @throws CampaignsException for an unknown filter or a value it can't use
     */
    public static function fromArray(array $filters): self
    {
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, static::KEYS, true)) {
                throw CampaignsException::invalidFilter((string) $key, 'there is no such filter; use '.implode(', ', static::KEYS));
            }
        }

        $lists = static::strings($filters, 'lists');
        $status = $filters['list_status'] ?? null;

        if ($status !== null && $lists === []) {
            throw CampaignsException::invalidFilter('list_status', 'it needs lists');
        }

        return new self(
            lists: $lists,
            listStatus: static::listStatus($status),
            locales: static::strings($filters, 'locales', nullable: true, max: 35),
            countries: array_map(fn (?string $country): ?string => $country === null ? null : static::country($country), static::strings($filters, 'countries', nullable: true)),
            tiers: array_map(
                fn (string $tier): EngagementTier => EngagementTier::tryFrom($tier)
                    ?? throw CampaignsException::invalidFilter('tiers', 'use '.implode(', ', array_column(EngagementTier::cases(), 'value'))),
                static::strings($filters, 'tiers'),
            ),
            optedOut: static::flag($filters, 'opted_out'),
            blocked: static::flag($filters, 'blocked'),
            sunset: static::flag($filters, 'sunset'),
            customFields: static::customFields($filters['custom_fields'] ?? []),
            dates: static::dates($filters['dates'] ?? []),
            campaigns: static::outcomes($filters['campaigns'] ?? []),
        );
    }

    /**
     * The filters as fromArray() reads them, with only the ones that narrow.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $filters = [];

        if ($this->lists !== []) {
            $filters['lists'] = $this->lists;
            $filters['list_status'] = $this->listStatus->value;
        }

        if ($this->locales !== []) {
            $filters['locales'] = $this->locales;
        }

        if ($this->countries !== []) {
            $filters['countries'] = $this->countries;
        }

        if ($this->tiers !== []) {
            $filters['tiers'] = array_map(fn (EngagementTier $tier): string => $tier->value, $this->tiers);
        }

        foreach (['opted_out' => $this->optedOut, 'blocked' => $this->blocked, 'sunset' => $this->sunset] as $key => $flag) {
            if ($flag !== null) {
                $filters[$key] = $flag;
            }
        }

        if ($this->customFields !== []) {
            $filters['custom_fields'] = $this->customFields;
        }

        if ($this->dates !== []) {
            $filters['dates'] = array_map(fn (array $date): array => [
                'field' => $date['field'],
                'after' => $date['after']?->toIso8601String(),
                'before' => $date['before']?->toIso8601String(),
            ], $this->dates);
        }

        if ($this->campaigns !== []) {
            $filters['campaigns'] = $this->campaigns;
        }

        return $filters;
    }

    /**
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     *
     * @throws CampaignsException when a list doesn't exist, so a mistyped key never reads as an empty audience
     */
    public function apply(Builder $query): Builder
    {
        if ($this->lists !== []) {
            $ids = CampaignList::query()->whereIn('key', $this->lists)->pluck('id', 'key');
            $missing = array_values(array_diff($this->lists, $ids->keys()->all()));

            if ($missing !== []) {
                throw CampaignsException::unknownLists($missing);
            }

            $query->whereHas('subscriptions', fn (Builder $query): Builder => $query
                ->whereIn('list_id', $ids->values())
                ->where('status', $this->listStatus));
        }

        static::whereInOrNull($query, 'locale', $this->locales);
        static::whereInOrNull($query, 'country', $this->countries);

        if ($this->tiers !== []) {
            $query->where(function (Builder $query): void {
                foreach ($this->tiers as $tier) {
                    $query->orWhere(fn (Builder $query): Builder => Engagement::whereTier($query, $tier));
                }
            });
        }

        if ($this->optedOut !== null) {
            $this->optedOut ? $query->whereNotNull('opted_out_at') : $query->whereNull('opted_out_at');
        }

        if ($this->blocked !== null) {
            $this->blocked ? $query->whereNotNull('blocked_at') : $query->whereNull('blocked_at');
        }

        if ($this->sunset !== null) {
            Engagement::whereSunset($query, $this->sunset);
        }

        foreach ($this->customFields as $field => $value) {
            $value === null
                ? $query->whereNull("custom_fields->{$field}")
                : $query->where("custom_fields->{$field}", $value);
        }

        foreach ($this->dates as $date) {
            $column = static::DATE_FIELDS[$date['field']];

            if ($date['after'] !== null) {
                $query->where($column, '>=', $date['after']);
            }

            if ($date['before'] !== null) {
                $query->where($column, '<', $date['before']);
            }
        }

        if ($this->campaigns !== []) {
            static::whereOutcomes($query, $this->campaigns);
        }

        return $query;
    }

    /**
     * @param  Builder<Subscriber>  $query
     * @param  list<array{campaign: string, outcome: value-of<self::OUTCOMES>}>  $outcomes
     *
     * @throws CampaignsException when a campaign doesn't exist
     */
    protected static function whereOutcomes(Builder $query, array $outcomes): void
    {
        $keys = array_values(array_unique(array_column($outcomes, 'campaign')));
        $ids = Campaign::query()->whereIn('key', $keys)->pluck('id', 'key');
        $missing = array_values(array_diff($keys, $ids->keys()->all()));

        if ($missing !== []) {
            throw CampaignsException::unknownCampaigns($missing);
        }

        $received = [MessageStatus::Sent, MessageStatus::Delivered];

        foreach ($outcomes as ['campaign' => $key, 'outcome' => $outcome]) {
            $constrain = match ($outcome) {
                'received', 'not_received' => fn (Builder $message): Builder => $message->whereIn('status', $received),
                'opened' => fn (Builder $message): Builder => $message->whereNotNull('opened_at'),
                'not_opened' => fn (Builder $message): Builder => $message->whereIn('status', $received)->whereNull('opened_at'),
                'clicked' => fn (Builder $message): Builder => $message->whereNotNull('clicked_at'),
                'not_clicked' => fn (Builder $message): Builder => $message->whereIn('status', $received)->whereNull('clicked_at'),
            };

            $ofCampaign = fn (Builder $message): Builder => $constrain($message->where('campaign_id', $ids[$key]));

            $outcome === 'not_received'
                ? $query->whereDoesntHave('messages', $ofCampaign)
                : $query->whereHas('messages', $ofCampaign);
        }
    }

    /**
     * @param  Builder<Subscriber>  $query
     * @param  list<?string>  $values
     */
    protected static function whereInOrNull(Builder $query, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $query->where(function (Builder $query) use ($column, $values): void {
            $given = array_values(array_filter($values, fn (?string $value): bool => $value !== null));

            if ($given !== []) {
                $query->whereIn($column, $given);
            }

            if (in_array(null, $values, true)) {
                $query->orWhereNull($column);
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return ($nullable is true ? list<?string> : list<string>)
     */
    protected static function strings(array $filters, string $key, bool $nullable = false, int $max = 125): array
    {
        $values = $filters[$key] ?? [];

        if (! is_array($values) || ! array_is_list($values)) {
            throw CampaignsException::invalidFilter($key, 'it takes a list');
        }

        static::bounded($key, $values);

        foreach ($values as $value) {
            if ($value === null && $nullable) {
                continue;
            }

            if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
                throw CampaignsException::invalidFilter($key, $nullable ? 'its values are text or null' : 'its values are text');
            }
        }

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    protected static function bounded(string $key, array $values): void
    {
        if (count($values) > static::MAX_VALUES) {
            throw CampaignsException::invalidFilter($key, 'it takes at most '.static::MAX_VALUES.' values');
        }
    }

    protected static function listStatus(mixed $status): SubscriptionStatus
    {
        if ($status === null) {
            return SubscriptionStatus::Subscribed;
        }

        return (is_string($status) ? SubscriptionStatus::tryFrom($status) : null)
            ?? throw CampaignsException::invalidFilter('list_status', 'use '.implode(', ', array_column(SubscriptionStatus::cases(), 'value')));
    }

    protected static function country(string $country): string
    {
        $country = strtoupper($country);

        if (preg_match('/^[A-Z]{2}\z/', $country) !== 1) {
            throw CampaignsException::invalidFilter('countries', 'use two-letter country codes');
        }

        return $country;
    }

    /**
     * @param  array<array-key, mixed>  $filters
     */
    protected static function flag(array $filters, string $key): ?bool
    {
        $value = $filters[$key] ?? null;

        if ($value !== null && ! is_bool($value)) {
            throw CampaignsException::invalidFilter($key, 'it is true or false');
        }

        return $value;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    protected static function customFields(mixed $fields): array
    {
        if (! is_array($fields) || array_is_list($fields) && $fields !== []) {
            throw CampaignsException::invalidFilter('custom_fields', 'it takes a map of field names to values');
        }

        static::bounded('custom_fields', $fields);

        foreach ($fields as $name => $value) {
            if (preg_match(static::CUSTOM_FIELD_NAME, (string) $name) !== 1) {
                throw CampaignsException::invalidFilter('custom_fields', 'a field name is letters, digits and underscores, starting with a letter or an underscore');
            }

            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw CampaignsException::invalidFilter('custom_fields', 'a value is text, a whole number, true, false or null');
            }
        }

        /** @var array<string, string|int|bool|null> $fields */
        return $fields;
    }

    /**
     * @return list<array{field: key-of<self::DATE_FIELDS>, after: ?CarbonImmutable, before: ?CarbonImmutable}>
     */
    protected static function dates(mixed $dates): array
    {
        if (! is_array($dates) || ! array_is_list($dates)) {
            throw CampaignsException::invalidFilter('dates', 'it takes a list of {field, after, before}');
        }

        static::bounded('dates', $dates);

        return array_map(function (mixed $date): array {
            $field = is_array($date) ? $date['field'] ?? null : null;

            if (! is_string($field) || ! array_key_exists($field, static::DATE_FIELDS)) {
                throw CampaignsException::invalidFilter('dates', 'each date names a field: '.implode(', ', array_keys(static::DATE_FIELDS)));
            }

            /** @var array<array-key, mixed> $date */
            if (array_diff(array_keys($date), ['field', 'after', 'before']) !== []) {
                throw CampaignsException::invalidFilter('dates', 'each date has only a field, an "after" and a "before"');
            }

            $after = static::date($date['after'] ?? null);
            $before = static::date($date['before'] ?? null);

            if ($after === null && $before === null) {
                throw CampaignsException::invalidFilter('dates', 'each date has an "after", a "before" or both');
            }

            return ['field' => $field, 'after' => $after, 'before' => $before];
        }, $dates);
    }

    /**
     * @return list<array{campaign: string, outcome: value-of<self::OUTCOMES>}>
     */
    protected static function outcomes(mixed $outcomes): array
    {
        if (! is_array($outcomes) || ! array_is_list($outcomes)) {
            throw CampaignsException::invalidFilter('campaigns', 'it takes a list of {campaign, outcome}');
        }

        static::bounded('campaigns', $outcomes);

        return array_map(function (mixed $outcome): array {
            $valid = is_array($outcome)
                && array_diff(array_keys($outcome), ['campaign', 'outcome']) === []
                && is_string($outcome['campaign'] ?? null) && Keys::isValid($outcome['campaign'])
                && in_array($outcome['outcome'] ?? null, static::OUTCOMES, true);

            if (! $valid) {
                throw CampaignsException::invalidFilter('campaigns', 'each names a campaign by its key and an outcome: '.implode(', ', static::OUTCOMES));
            }

            /** @var array{campaign: string, outcome: value-of<self::OUTCOMES>} $outcome */
            return ['campaign' => $outcome['campaign'], 'outcome' => $outcome['outcome']];
        }, $outcomes);
    }

    /**
     * In the app's timezone, since stored timestamps carry none and a bound
     * date is compared as written.
     */
    protected static function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        // A blank would parse as now: "after now" would quietly match nobody.
        if (! is_string($value) || trim($value) === '') {
            throw CampaignsException::invalidFilter('dates', 'a date is text, never blank');
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            throw CampaignsException::invalidFilter('dates', 'a date can\'t be read');
        }
    }
}
