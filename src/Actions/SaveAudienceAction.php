<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\SavedAudience;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\TextValue;

/**
 * Saves a named set of built-in filters, or replaces the filters of the one
 * saved under that key. A campaign that names it reads its filters when it
 * starts, so a change reaches every campaign not yet started.
 */
class SaveAudienceAction
{
    /**
     * @param  array<string, mixed>  $filters  as SubscriberFilters::fromArray() reads them
     *
     * @throws CampaignsException
     */
    public function execute(string $key, string $name, array $filters, ?string $description = null): SavedAudience
    {
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidKey($key);
        }

        $values = [
            'name' => TextValue::required('name', $name, 255),
            'description' => TextValue::optional('description', $description, 5000),
            'filters' => static::filters($filters),
        ];

        $audience = Rows::firstOrCreate(SavedAudience::query(), ['key' => $key], $values);

        if (! $audience->wasRecentlyCreated) {
            $audience->forceFill($values)->save();
        }

        return $audience;
    }

    /**
     * The filters as they are stored, after their lists and campaigns are
     * found, so a mistyped key is refused now rather than at a start.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected static function filters(array $filters): array
    {
        $parsed = SubscriberFilters::fromArray($filters);
        $parsed->apply(Subscriber::query());

        return $parsed->toArray();
    }
}
