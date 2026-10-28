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

/**
 * Brings one app record's subscriber and its place on a dynamic list in
 * step with the record: links or makes the subscriber, updates its details,
 * follows an address change, and takes the record off the list while it
 * isn't eligible.
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

        return (new Subscriber)->getConnection()->transaction(function () use ($listKey, $record, $context, $fields, $eligible, $address): SyncResult {
            $list = $this->campaigns->list($listKey);
            $subscriber = Subscriber::findFor($record);
            $changes = [];

            if ($subscriber === null && ! $eligible) {
                return new SyncResult;
            }

            if ($address === null) {
                return new SyncResult(problem: 'no valid email address');
            }

            if ($subscriber !== null && $subscriber->email !== $address) {
                try {
                    $subscriber = $this->changeEmail->execute($subscriber, $address, $context ?? new ConsentContext('sync'));
                } catch (CampaignsException) {
                    return new SyncResult(problem: 'its address belongs to another record');
                }

                $changes[] = SyncChange::EmailChanged;
            }

            if ($subscriber === null) {
                $subscriber = Subscriber::query()->firstOrCreate(['email' => $address]);

                if ($subscriber->subscribable_type !== null) {
                    return new SyncResult(problem: 'its address belongs to another record');
                }

                $subscriber->subscribable()->associate($record);
                $changes[] = $subscriber->wasRecentlyCreated ? SyncChange::Created : SyncChange::Linked;
            }

            $details = $this->details($fields, $subscriber);
            $subscriber->fill($details);

            if (! $subscriber->wasRecentlyCreated && $subscriber->isDirty(array_keys($details))) {
                $changes[] = SyncChange::Updated;
            }

            $subscriber->save();

            $subscription = Subscription::query()
                ->where('list_id', $list->id)
                ->where('subscriber_id', $subscriber->id)
                ->first();

            if (! $eligible) {
                if ($subscription !== null && $subscription->status !== SubscriptionStatus::Unsubscribed) {
                    $subscription->update(['status' => SubscriptionStatus::Unsubscribed, 'unsubscribed_at' => now()]);
                    $changes[] = SyncChange::Removed;
                }

                return new SyncResult($changes, suppressed: $subscriber->isSuppressed());
            }

            if ($subscription?->status === SubscriptionStatus::Unsubscribed) {
                $subscription->update(['status' => SubscriptionStatus::Subscribed, 'unsubscribed_at' => null]);
                $changes[] = SyncChange::Restored;
            }

            $context ??= new ConsentContext('sync', basis: $this->predatesList($record, $list) ? ConsentBasis::Legacy : null);

            $joined = $context->accepted === false
                ? $this->decline->execute($subscriber, $list, $context)
                : $this->subscribe->execute($subscriber, $list, $context);

            if ($joined->wasRecentlyCreated) {
                $changes[] = $context->accepted === false ? SyncChange::Declined : SyncChange::Added;
            }

            return new SyncResult($changes, suppressed: $subscriber->isSuppressed());
        });
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
        $details = [];

        foreach (['name' => 255, 'first_name' => 255, 'locale' => 35, 'timezone' => 64] as $field => $length) {
            if (array_key_exists($field, $fields)) {
                $details[$field] = $this->cut($fields[$field], $length);
            }
        }

        if (array_key_exists('country', $fields)) {
            $country = strtoupper(trim((string) $fields['country']));
            $details['country'] = preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
        }

        if (is_array($fields['custom_fields'] ?? null)) {
            $details['custom_fields'] = [...$subscriber->custom_fields ?? [], ...$fields['custom_fields']];
        }

        return $details;
    }

    protected function cut(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
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
