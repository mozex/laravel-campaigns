<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Mozex\Campaigns\Audiences\AudienceDefinition;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\Pace;

/**
 * A campaign's settings as an agent gives them, checked and turned into its
 * columns. Only the settings given change; pace and thresholds merge into
 * what the campaign already has.
 */
class CampaignSettings
{
    public const KEYS = ['name', 'from_name', 'from_email', 'reply_to', 'lists', 'audience', 'track_opens', 'track_clicks', 'utm', 'pace', 'thresholds'];

    public const UTM_TAGS = ['source', 'medium', 'campaign', 'content', 'term'];

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array<string, mixed>
     *
     * @throws CampaignsException naming the first setting it can't use
     */
    public static function columns(array $settings, ?Campaign $campaign = null): array
    {
        $columns = [];

        foreach ($settings as $key => $value) {
            $columns = [...$columns, ...match ($key) {
                'name' => ['name' => static::text('name', $value, 255, required: true)],
                'from_name' => ['from_name' => static::text('from_name', $value, 255)],
                'from_email' => ['from_email' => static::from($value)],
                'reply_to' => ['reply_to' => $value === null ? null : static::address('reply_to', $value)],
                'lists' => ['target_lists' => static::lists($value)],
                'audience' => ['audience' => static::audience($value)],
                'track_opens', 'track_clicks' => [(string) $key => static::flag((string) $key, $value)],
                'utm' => ['utm' => static::utm($value)],
                'pace' => ['pace' => Pace::validate(static::map('pace', $value))],
                'thresholds' => ['thresholds' => static::thresholds($value)],
                default => throw CampaignsException::invalidSetting((string) $key, 'there is no such setting; use '.implode(', ', static::KEYS)),
            }];
        }

        // The audience is checked whole, against the lists it will have, so
        // a named audience's parameters are validated now, not at the start.
        if (array_key_exists('target_lists', $columns) || array_key_exists('audience', $columns)) {
            $definition = AudienceDefinition::fromStored(array_key_exists('audience', $columns) ? $columns['audience'] : $campaign?->audience);
            $lists = $columns['target_lists'] ?? $campaign->target_lists ?? [];

            $lists === []
                ? $definition->apply(Subscriber::query())
                : (new CampaignAudience($lists, $definition))->targeted();
        }

        return $columns;
    }

    /**
     * The pace and thresholds given, laid over what the campaign has, so a
     * setting left out keeps its value.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    public static function merged(array $columns, Campaign $campaign): array
    {
        foreach (['pace', 'thresholds'] as $column) {
            if (array_key_exists($column, $columns)) {
                $columns[$column] = [...$campaign->getAttribute($column) ?? [], ...$columns[$column]];
            }
        }

        return $columns;
    }

    /**
     * The sender's address, which must be on the sending domain.
     *
     * @throws CampaignsException
     */
    public static function from(mixed $value): string
    {
        $address = static::address('from_email', $value);

        if (! Sender::isOnDomain($address)) {
            throw CampaignsException::notOnSendingDomain(Sender::domain());
        }

        return $address;
    }

    protected static function text(string $key, mixed $value, int $max, bool $required = false): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_string($value)) {
            throw CampaignsException::invalidSetting($key, 'it is text');
        }

        // A name becomes part of a mail header.
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw CampaignsException::invalidSetting($key, 'it must fit on one line');
        }

        return $required ? TextValue::required($key, $value, $max) : TextValue::optional($key, $value, $max);
    }

    protected static function address(string $key, mixed $value): string
    {
        return (is_string($value) ? EmailAddress::normalize($value) : null)
            ?? throw CampaignsException::invalidSetting($key, 'it is an email address');
    }

    /**
     * @return list<int>
     */
    protected static function lists(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw CampaignsException::invalidSetting('lists', 'it takes a list of one or more list keys');
        }

        foreach ($value as $key) {
            if (! is_string($key) || ! Keys::isValid($key)) {
                throw CampaignsException::invalidSetting('lists', 'a list is named by its key');
            }
        }

        $ids = CampaignList::query()->whereIn('key', $value)->pluck('id', 'key');
        $missing = array_values(array_diff($value, $ids->keys()->all()));

        if ($missing !== []) {
            throw CampaignsException::unknownLists($missing);
        }

        return $ids->values()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * @return ?array<string, mixed>
     */
    protected static function audience(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return AudienceDefinition::fromArray(static::map('audience', $value))->toArray() ?: null;
    }

    protected static function flag(string $key, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw CampaignsException::invalidSetting($key, 'it is true or false');
        }

        return $value;
    }

    /**
     * Null for the defaults, an empty array for no tags, else the tags that
     * replace the defaults.
     *
     * @return ?array<string, string>
     */
    protected static function utm(mixed $value): ?array
    {
        if ($value === null || $value === true) {
            return null;
        }

        if ($value === false) {
            return [];
        }

        $tags = [];

        foreach (static::map('utm', $value) as $name => $tag) {
            if (! in_array($name, static::UTM_TAGS, true)) {
                throw CampaignsException::invalidSetting('utm', 'its tags are '.implode(', ', static::UTM_TAGS));
            }

            if (! is_string($tag)) {
                throw CampaignsException::invalidSetting('utm', 'a tag is text');
            }

            $tags[$name] = TextValue::required("utm {$name}", $tag, 125);
        }

        return $tags;
    }

    /**
     * @return array<string, int|float>
     */
    protected static function thresholds(mixed $value): array
    {
        $known = array_keys((array) config('campaigns.stop_loss', []));
        $thresholds = [];

        foreach (static::map('thresholds', $value) as $key => $threshold) {
            if (! in_array($key, $known, true)) {
                throw CampaignsException::invalidSetting('thresholds', 'there is no threshold "'.mb_strimwidth((string) $key, 0, 40, '...').'"; use '.implode(', ', $known));
            }

            if (! is_int($threshold) && ! is_float($threshold) || $threshold < 0) {
                throw CampaignsException::invalidSetting('thresholds', "{$key} is a number of at least 0");
            }

            $thresholds[(string) $key] = $threshold;
        }

        return $thresholds;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function map(string $key, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw CampaignsException::invalidSetting($key, 'it takes a map of names to values');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
