<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Subscriber;

/**
 * Sets a subscriber's custom fields, the free-form data filters and saved
 * audiences target. The fields given are written over the ones it has, a
 * null removing one; the rest stay. A field a dynamic list's source keeps in
 * step is written again by its next sync.
 */
class SetCustomFieldsAction
{
    public const MAX_FIELDS = 100;

    public const MAX_TEXT = 1000;

    /**
     * @param  array<string, string|int|bool|null>  $fields
     *
     * @throws CampaignsException
     */
    public function execute(Subscriber $subscriber, array $fields): Subscriber
    {
        static::check($fields);

        return $subscriber->getConnection()->transaction(function () use ($subscriber, $fields): Subscriber {
            // Locked, so a sync merging its own fields at the same moment
            // can't lose these.
            $locked = Subscriber::query()->whereKey($subscriber->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::subscriberDeleted();

            $merged = array_filter([...$locked->custom_fields ?? [], ...$fields], fn (mixed $value): bool => $value !== null);

            if (count($merged) > static::MAX_FIELDS) {
                throw CampaignsException::invalidValue('custom fields', 'may number at most '.static::MAX_FIELDS.' per subscriber');
            }

            $locked->custom_fields = $merged === [] ? null : $merged;
            $locked->save();

            return $locked;
        });
    }

    /**
     * The rules every custom field meets, however it arrives (this action,
     * an import, an agent adding a contact): a filter can find it by its
     * name, and it shows in a lookup.
     *
     * @param  array<array-key, mixed>  $fields
     *
     * @throws CampaignsException
     */
    public static function check(array $fields): void
    {
        if ($fields === [] || array_is_list($fields)) {
            throw CampaignsException::invalidValue('custom fields', 'are a map of field names to values');
        }

        if (count($fields) > static::MAX_FIELDS) {
            throw CampaignsException::invalidValue('custom fields', 'may number at most '.static::MAX_FIELDS.' per subscriber');
        }

        foreach ($fields as $name => $value) {
            if (preg_match(SubscriberFilters::CUSTOM_FIELD_NAME, (string) $name) !== 1) {
                throw CampaignsException::invalidValue('custom field names', 'are letters, digits and underscores, starting with a letter or an underscore');
            }

            $text = is_string($value) && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0") && mb_strlen($value) <= static::MAX_TEXT;

            if ($value !== null && ! is_int($value) && ! is_bool($value) && ! $text) {
                throw CampaignsException::invalidValue("custom field \"{$name}\"", 'is text of at most '.static::MAX_TEXT.' characters, a whole number, true, false or null');
            }
        }
    }
}
