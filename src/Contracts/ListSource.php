<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Feeds a dynamic list from the app's own records, usually its users. The
 * list is keyed in campaigns.list_sources, and the model uses the
 * HasCampaignSubscriber trait so its changes reach the list as they happen.
 */
interface ListSource
{
    /**
     * The list's name, used when the package creates the list.
     */
    public function name(): string;

    /**
     * Every record of the model, for the sync command to walk, with whatever
     * fields() and isEligible() read eager-loaded. Leave eligibility out of
     * the query: the model's own changes are checked with isEligible() alone.
     * Select whole rows: the sync reads the created and deleted timestamps,
     * which strict models refuse when they weren't selected.
     *
     * @return Builder<covariant Model>
     */
    public function query(): Builder;

    /**
     * Whether the record belongs on the list now, for a rule of the app's
     * own, such as a ban. A record that stops being eligible leaves the list
     * and comes back when it is eligible again; neither is a consent change.
     */
    public function isEligible(Model $record): bool;

    /**
     * The subscriber's details for the record. Only the keys returned are
     * kept in step, so leave out what the record doesn't hold; custom_fields
     * is merged into what the subscriber already has.
     *
     * @return array{email: ?string, name?: ?string, first_name?: ?string, locale?: ?string, timezone?: ?string, country?: ?string, custom_fields?: ?array<string, mixed>}
     */
    public function fields(Model $record): array;
}
