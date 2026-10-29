<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Audiences;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Keys;

/**
 * Who a campaign goes to within its target lists: a named audience with its
 * parameters, a saved audience, built-in filters, the people named by
 * subscriber id or address, and app records. Every part given narrows (they
 * combine with AND); a part left out narrows nothing, so with none the
 * campaign goes to everyone on its lists. Subscriber ids and addresses name
 * one set of people, and a set given that names nobody (addresses that
 * matched no subscriber) reaches nobody, never everyone. Addresses are
 * turned into subscriber ids when the audience is set, so a stored audience
 * holds no address, and the number that matched nobody is kept instead.
 */
readonly class AudienceDefinition
{
    public const KEYS = ['audience', 'saved_audience', 'filters', 'subscribers', 'emails', 'records'];

    /**
     * The most ids or addresses one part may name; a larger set is a filter,
     * a saved audience or a named audience.
     */
    public const MAX_IDS = 10_000;

    /**
     * @param  array<string, mixed>  $parameters  the named audience's
     * @param  ?list<int>  $subscribers  the people named by id or address; null when none were named
     * @param  array<string, list<int|string>>  $records  app record ids by the key of the dynamic list whose source holds them
     * @param  int  $unmatchedEmails  how many addresses given matched no subscriber
     */
    public function __construct(
        public ?string $audience = null,
        public array $parameters = [],
        public ?string $savedAudience = null,
        public ?SubscriberFilters $filters = null,
        public ?array $subscribers = null,
        public array $records = [],
        public int $unmatchedEmails = 0,
    ) {}

    /**
     * From what an agent sends, keyed by KEYS: {"audience": {"name": ...,
     * "parameters": {...}}, "saved_audience": key, "filters": {...},
     * "subscribers": [ids], "emails": [addresses], "records": {list: [ids]}}.
     *
     * @param  array<array-key, mixed>  $given
     *
     * @throws CampaignsException naming the first part it can't use
     */
    public static function fromArray(array $given): self
    {
        foreach (array_keys($given) as $key) {
            if (! in_array($key, static::KEYS, true)) {
                throw CampaignsException::invalidAudience((string) $key, 'there is no such part; use '.implode(', ', static::KEYS));
            }
        }

        [$audience, $parameters] = static::named($given['audience'] ?? null);
        $saved = $given['saved_audience'] ?? null;

        if ($saved !== null && (! is_string($saved) || ! SavedAudience::query()->where('key', $saved)->exists())) {
            throw CampaignsException::invalidAudience('saved_audience', 'no saved audience has that key');
        }

        $filters = $given['filters'] ?? null;

        if ($filters !== null && ! is_array($filters)) {
            throw CampaignsException::invalidAudience('filters', 'it takes a map of filters');
        }

        [$fromEmails, $unmatched] = static::emails($given['emails'] ?? null);
        $named = isset($given['subscribers']) || isset($given['emails'])
            ? array_values(array_unique([...static::ids($given['subscribers'] ?? null), ...$fromEmails]))
            : null;

        return new self(
            audience: $audience,
            parameters: $parameters,
            savedAudience: $saved,
            filters: $filters === null || $filters === [] ? null : SubscriberFilters::fromArray($filters),
            subscribers: $named,
            records: static::records($given['records'] ?? null),
            unmatchedEmails: $unmatched,
        );
    }

    /**
     * From what a campaign stored (toArray()).
     *
     * @param  ?array<string, mixed>  $stored
     */
    public static function fromStored(?array $stored): self
    {
        $stored ??= [];

        /** @var array{name?: string, parameters?: array<string, mixed>} $named */
        $named = (array) ($stored['audience'] ?? []);

        /** @var ?list<int> $subscribers */
        $subscribers = $stored['subscribers'] ?? null;

        /** @var array<string, list<int|string>> $records */
        $records = (array) ($stored['records'] ?? []);

        return new self(
            audience: $named['name'] ?? null,
            parameters: $named['parameters'] ?? [],
            savedAudience: isset($stored['saved_audience']) ? (string) $stored['saved_audience'] : null,
            filters: empty($stored['filters']) ? null : SubscriberFilters::fromArray((array) $stored['filters']),
            subscribers: $subscribers,
            records: $records,
            unmatchedEmails: (int) ($stored['unmatched_emails'] ?? 0),
        );
    }

    /**
     * As a campaign stores it: no addresses, only what they matched.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'audience' => $this->audience === null ? null : ['name' => $this->audience, 'parameters' => $this->parameters],
            'saved_audience' => $this->savedAudience,
            'filters' => $this->filters?->toArray() ?: null,
            // Kept when empty: a set given that names nobody reaches nobody.
            'subscribers' => $this->subscribers,
            'records' => $this->records ?: null,
            'unmatched_emails' => $this->unmatchedEmails ?: null,
        ], fn (mixed $part): bool => $part !== null);
    }

    /**
     * Narrows a subscriber query to this audience.
     *
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     *
     * @throws CampaignsException for a list, campaign or saved audience that no longer exists
     */
    public function apply(Builder $query): Builder
    {
        if ($this->audience !== null) {
            $query->whereIn('id', Campaigns::audienceSubscribers($this->audience, $this->parameters)->select('id'));
        }

        if ($this->savedAudience !== null) {
            $saved = SavedAudience::query()->where('key', $this->savedAudience)->first()
                ?? throw CampaignsException::invalidAudience('saved_audience', "no saved audience has the key \"{$this->savedAudience}\" any more");

            SubscriberFilters::fromArray($saved->filters)->apply($query);
        }

        $this->filters?->apply($query);

        if ($this->subscribers === []) {
            $query->whereRaw('1 = 0');
        } elseif ($this->subscribers !== null) {
            $query->whereIntegerInRaw('id', $this->subscribers);
        }

        if ($this->records !== []) {
            $query->where(function (Builder $query): void {
                foreach ($this->records as $list => $ids) {
                    $model = Campaigns::listSource($list)->query()->getModel();

                    $query->orWhere(fn (Builder $query): Builder => $query
                        ->where('subscribable_type', $model->getMorphClass())
                        ->whereIn('subscribable_id', $ids));
                }
            });
        }

        return $query;
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    protected static function named(mixed $named): array
    {
        if ($named === null) {
            return [null, []];
        }

        $name = is_array($named) ? $named['name'] ?? null : null;
        $parameters = is_array($named) ? $named['parameters'] ?? [] : null;

        if (! is_string($name) || ! is_array($parameters) || array_is_list($parameters) && $parameters !== []
            || array_diff(array_keys((array) $named), ['name', 'parameters']) !== []) {
            throw CampaignsException::invalidAudience('audience', 'it is {"name": ..., "parameters": {...}}');
        }

        // Throws for an audience the app doesn't configure.
        Campaigns::audience($name);

        /** @var array<string, mixed> $parameters */
        return [$name, $parameters];
    }

    /**
     * @return list<int>
     */
    protected static function ids(mixed $ids): array
    {
        if ($ids === null) {
            return [];
        }

        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > static::MAX_IDS) {
            throw CampaignsException::invalidAudience('subscribers', 'it takes a list of at most '.static::MAX_IDS.' subscriber ids');
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw CampaignsException::invalidAudience('subscribers', 'a subscriber id is a whole number');
            }
        }

        return $ids;
    }

    /**
     * The subscribers the addresses belong to, and how many matched none.
     *
     * @return array{0: list<int>, 1: int}
     */
    protected static function emails(mixed $emails): array
    {
        if ($emails === null) {
            return [[], 0];
        }

        if (! is_array($emails) || ! array_is_list($emails) || count($emails) > static::MAX_IDS) {
            throw CampaignsException::invalidAudience('emails', 'it takes a list of at most '.static::MAX_IDS.' addresses');
        }

        $clean = [];

        foreach ($emails as $email) {
            // The address is never named: an agent's chat shows the error.
            $clean[] = (is_string($email) ? EmailAddress::normalize($email) : null)
                ?? throw CampaignsException::invalidAudience('emails', 'one of them isn\'t an email address');
        }

        $clean = array_values(array_unique($clean));
        $ids = [];

        foreach (array_chunk($clean, 1000) as $chunk) {
            foreach (Subscriber::query()->whereIn('email', $chunk)->pluck('id') as $id) {
                $ids[] = (int) $id;
            }
        }

        return [$ids, count($clean) - count($ids)];
    }

    /**
     * @return array<string, list<int|string>>
     */
    protected static function records(mixed $records): array
    {
        if ($records === null) {
            return [];
        }

        if (! is_array($records) || array_is_list($records) && $records !== []) {
            throw CampaignsException::invalidAudience('records', 'it takes a map of dynamic list keys to the ids of their records');
        }

        $total = 0;

        foreach ($records as $list => $ids) {
            if (! is_string($list) || ! Keys::isValid($list)) {
                throw CampaignsException::invalidAudience('records', 'a key names a dynamic list');
            }

            // Throws for a list no source feeds.
            $model = Campaigns::listSource($list)->query()->getModel();
            $integers = in_array($model->getKeyType(), ['int', 'integer'], true);

            if (! is_array($ids) || ! array_is_list($ids)) {
                throw CampaignsException::invalidAudience('records', 'each list takes a list of record ids');
            }

            // Checked against the record's key type, since PostgreSQL refuses
            // a text value for an integer column with a database error.
            foreach ($ids as $id) {
                $valid = $integers
                    ? is_int($id) && $id >= 1
                    : is_string($id) && $id !== '' && strlen($id) <= 64;

                if (! $valid) {
                    throw CampaignsException::invalidAudience('records', $integers ? "the \"{$list}\" records' ids are whole numbers" : "the \"{$list}\" records' ids are short strings");
                }
            }

            $total += count($ids);
        }

        if ($total > static::MAX_IDS) {
            throw CampaignsException::invalidAudience('records', 'it may name at most '.static::MAX_IDS.' records');
        }

        /** @var array<string, list<int|string>> $records */
        return array_map(fn (array $ids): array => array_values(array_unique($ids, SORT_REGULAR)), $records);
    }
}
