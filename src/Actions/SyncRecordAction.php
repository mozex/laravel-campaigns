<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\ListSource;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Enums\SyncChange;
use Mozex\Campaigns\Lists\SyncResult;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Rows;
use Mozex\Campaigns\Support\SubscriberDetails;

/**
 * Brings one app record's subscriber and its place on a dynamic list in
 * step with the record: links or makes the subscriber, updates its details,
 * follows an address change, and takes the record off the list while it
 * isn't eligible or has no address of its own to sync.
 *
 * Joining the list goes through SubscribeAction, so a subscription that
 * exists is never changed and an opt-out or a block is never cleared,
 * except when the record is created with the person's own choice in its
 * consent context (a ticked or unticked sign-up box). Leaving and rejoining
 * for eligibility writes no consent records: it isn't a consent change.
 */
class SyncRecordAction
{
    /**
     * The subscriber fields a list source may return.
     */
    protected const FIELDS = ['email', 'name', 'first_name', 'locale', 'timezone', 'country', 'custom_fields'];

    public function __construct(
        protected Campaigns $campaigns,
        protected SubscribeAction $subscribe,
        protected DeclineAction $decline,
        protected ChangeEmailAction $changeEmail,
    ) {}

    /**
     * @param  ?ConsentContext  $context  The consent the record was created under; null for a sync of a record that already existed.
     */
    public function execute(string $listKey, Model $record, ?ConsentContext $context = null): SyncResult
    {
        $source = $this->campaigns->listSource($listKey);
        $fields = $this->fields($source, $record);
        $eligible = ! $this->isTrashed($record) && $source->isEligible($record);
        $address = EmailAddress::normalize($fields['email'] ?? null);

        // A record created with the person's choice records it even when the
        // record can't join its list yet, so a later sync never subscribes
        // someone who left the box unticked.
        $choice = $context?->accepted !== null;

        return (new Subscriber)->getConnection()->transaction(function () use ($listKey, $record, $context, $fields, $eligible, $address, $choice): SyncResult {
            $list = $this->campaigns->list($listKey);
            $subscriber = Subscriber::findFor($record);
            $changes = [];
            $problem = $address === null ? 'It has no email address that can be mailed.' : null;

            if ($subscriber === null && ! $eligible && ! $choice) {
                return new SyncResult;
            }

            if ($problem === null && $subscriber !== null && $subscriber->email !== $address) {
                try {
                    $subscriber = $this->changeEmail->execute($subscriber, $address, $context ?? new ConsentContext('sync'));
                    $changes[] = SyncChange::EmailChanged;
                } catch (CampaignsException $exception) {
                    $problem = $exception->getMessage();
                }
            }

            if ($problem === null && $subscriber === null) {
                $subscriber = Rows::firstOrCreate(Subscriber::query(), ['email' => $address]);

                if ($subscriber->subscribable_type === null) {
                    $subscriber->subscribable()->associate($record);
                    $changes[] = $subscriber->wasRecentlyCreated ? SyncChange::Created : SyncChange::Linked;
                } elseif (! $this->linksTo($subscriber, $record)) {
                    $problem = CampaignsException::addressTaken()->getMessage();
                    $subscriber = null;
                }
            }

            // The subscriber still holds an address the record no longer
            // has, so it leaves the list until the record can be synced.
            if ($problem !== null) {
                if ($subscriber !== null && $this->takeOff($subscriber, $list)) {
                    $changes[] = SyncChange::Removed;
                }

                return new SyncResult($changes, $problem, $subscriber?->isSuppressed() ?? false);
            }

            $details = $this->details($fields, $subscriber);
            $subscriber->fill($details);

            if (! $subscriber->wasRecentlyCreated && $subscriber->isDirty(array_keys($details))) {
                $changes[] = SyncChange::Updated;
            }

            $subscriber->save();

            if (! $eligible && ! $choice) {
                if ($this->takeOff($subscriber, $list)) {
                    $changes[] = SyncChange::Removed;
                }

                return new SyncResult($changes, suppressed: $subscriber->isSuppressed());
            }

            if ($eligible && $this->putBack($subscriber, $list)) {
                $changes[] = SyncChange::Restored;
            }

            $context ??= new ConsentContext('sync', basis: $this->predatesList($record, $list) ? ConsentBasis::Legacy : null);

            $declined = $context->accepted === false;

            $joined = $declined
                ? $this->decline->execute($subscriber, $list, $context)
                : $this->subscribe->execute($subscriber, $list, $context);

            if ($joined->wasRecentlyCreated) {
                $changes[] = $declined ? SyncChange::Declined : SyncChange::Added;
            }

            // The choice is recorded; the record joins once it is eligible.
            if (! $eligible) {
                $this->takeOff($subscriber, $list);
            }

            return new SyncResult($changes, suppressed: $subscriber->isSuppressed());
        });
    }

    /**
     * Takes the subscriber off a dynamic list while its record can't be on
     * it. Not a consent change, so no consent record.
     */
    protected function takeOff(Subscriber $subscriber, CampaignList $list): bool
    {
        return Subscription::query()
            ->where('list_id', $list->id)
            ->where('subscriber_id', $subscriber->id)
            ->where('status', '!=', SubscriptionStatus::Unsubscribed)
            ->update(['status' => SubscriptionStatus::Unsubscribed, 'unsubscribed_at' => now(), 'updated_at' => now()]) > 0;
    }

    protected function putBack(Subscriber $subscriber, CampaignList $list): bool
    {
        return Subscription::query()
            ->where('list_id', $list->id)
            ->where('subscriber_id', $subscriber->id)
            ->where('status', SubscriptionStatus::Unsubscribed)
            ->update(['status' => SubscriptionStatus::Subscribed, 'unsubscribed_at' => null, 'updated_at' => now()]) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fields(ListSource $source, Model $record): array
    {
        $fields = $source->fields($record);

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, static::FIELDS, true)) {
                throw CampaignsException::unknownField($source::class, (string) $field);
            }
        }

        // The rules custom fields meet however they arrive, so a filter can
        // find them and an answer showing the person stays under its limit.
        if (is_array($fields['custom_fields'] ?? null) && $fields['custom_fields'] !== []) {
            SetCustomFieldsAction::check($fields['custom_fields']);
        }

        return $fields;
    }

    /**
     * The details the source maps, cut to their columns. custom_fields is
     * merged into what the subscriber has, so data an import added stays.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function details(array $fields, Subscriber $subscriber): array
    {
        $details = SubscriberDetails::clean($fields);

        if (is_array($fields['custom_fields'] ?? null)) {
            $details['custom_fields'] = [...$subscriber->custom_fields ?? [], ...$fields['custom_fields']];

            if (count($details['custom_fields']) > SetCustomFieldsAction::MAX_FIELDS) {
                throw CampaignsException::invalidValue('custom fields', 'may number at most '.SetCustomFieldsAction::MAX_FIELDS.' per subscriber');
            }
        }

        return $details;
    }

    /**
     * Whether the subscriber is already the record's own, linked a moment ago
     * by another request syncing the same record.
     */
    protected function linksTo(Subscriber $subscriber, Model $record): bool
    {
        return $subscriber->subscribable_type === $record->getMorphClass()
            && (string) $subscriber->subscribable_id === (string) $record->getKey();
    }

    protected function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }

    /**
     * A record older than its list existed before the app recorded consent,
     * so it was never offered a way to opt out.
     */
    protected function predatesList(Model $record, CampaignList $list): bool
    {
        $createdAt = $record->usesTimestamps() ? $record->getAttribute($record->getCreatedAtColumn()) : null;

        return $createdAt === null || $list->created_at === null || $createdAt < $list->created_at;
    }
}
