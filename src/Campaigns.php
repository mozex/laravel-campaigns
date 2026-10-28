<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Contracts\ListSource;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Rows;
use Throwable;

class Campaigns
{
    /**
     * The consent each model class is being created under, set only for the
     * length of a withConsent() callback.
     *
     * @var array<class-string<Model>, ConsentContext>
     */
    protected array $consents = [];

    /**
     * Runs the callback with a consent context for records of the model
     * created inside it, such as a sign-up's ticked or unticked box. Records
     * of other models, and anything created after the callback returns or
     * throws, never see it.
     *
     * @template TReturn
     *
     * @param  class-string<Model>  $model
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withConsent(string $model, ConsentContext $context, Closure $callback): mixed
    {
        $previous = $this->consents[$model] ?? null;
        $this->consents[$model] = $context;

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                unset($this->consents[$model]);
            } else {
                $this->consents[$model] = $previous;
            }
        }
    }

    public function consentFor(Model $record): ?ConsentContext
    {
        return $this->consents[$record::class] ?? null;
    }

    public function listSource(string $key): ListSource
    {
        /** @var ?class-string $class */
        $class = config('campaigns.list_sources', [])[$key] ?? null;

        if ($class === null) {
            throw CampaignsException::unknownList($key);
        }

        $source = app($class);

        if (! $source instanceof ListSource) {
            throw CampaignsException::invalidListSource($key, $class);
        }

        return $source;
    }

    /**
     * The sources whose model the record is, keyed by list. A source that
     * can't be resolved is reported and skipped, so one mistake in the
     * config can't stop the app's models from saving.
     *
     * @return array<string, ListSource>
     */
    public function listSourcesFor(Model $record): array
    {
        $sources = [];

        foreach (array_keys((array) config('campaigns.list_sources', [])) as $key) {
            try {
                $source = $this->listSource((string) $key);
                $model = $source->query()->getModel();
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            if ($model::class === $record::class) {
                $sources[(string) $key] = $source;
            }
        }

        return $sources;
    }

    /**
     * The dynamic list a source feeds, created the first time it is needed.
     * Its creation time marks when the app started recording consent, so
     * records older than it count as legacy.
     *
     * @throws CampaignsException when a static list already has the key
     */
    public function list(string $key): CampaignList
    {
        $source = $this->listSource($key);

        $list = Rows::firstOrCreate(CampaignList::query(), ['key' => $key], [
            'name' => $source->name(),
            'kind' => ListKind::Dynamic,
            'double_opt_in' => false,
            'default_status' => SubscriptionStatus::Subscribed,
            'default_basis' => ConsentBasis::SoftOptIn,
        ]);

        if ($list->kind !== ListKind::Dynamic) {
            throw CampaignsException::notDynamic($key);
        }

        return $list;
    }

    /**
     * Notes that the person behind an app record (or a subscriber) did
     * something in the app, such as signing in or buying. It counts as
     * engagement, so an address that is active in the app is never treated
     * as silent. Returns false when there is no subscriber, or it already
     * has a later activity.
     */
    public function recordActivity(Model $record, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        $query = $record instanceof Subscriber
            ? Subscriber::query()->whereKey($record->getKey())
            : Subscriber::query()->whereMorphedTo('subscribable', $record);

        return $query
            ->where(fn (Builder $query): Builder => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $at))
            ->update(['last_active_at' => $at, 'updated_at' => now()]) > 0;
    }
}
