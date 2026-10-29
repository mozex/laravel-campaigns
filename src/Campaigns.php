<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Contracts\AgentResource;
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Contracts\ListSource;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Support\Keys;
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

    /**
     * What the pages and emails call the app.
     */
    public function name(): string
    {
        return (string) (config('campaigns.name') ?: config('app.name'));
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

        // Campaigns and audiences name lists by key, which the config could
        // otherwise hold in a form they refuse.
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidListSourceKey($key);
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
     * @throws CampaignsException when nothing is configured under the key, or it isn't an Audience
     */
    public function audience(string $key): Audience
    {
        return $this->extension('audiences', $key, Audience::class);
    }

    /**
     * Every configured audience, keyed by name. One that can't be resolved
     * is reported and left out, so one mistake doesn't hide the rest.
     *
     * @return array<string, Audience>
     */
    public function audiences(): array
    {
        return $this->extensions('audiences', Audience::class);
    }

    /**
     * The subscribers in a named audience: its parameters validated by its
     * rules, then its query, turned into subscribers through the record link
     * when it queries the app's own records. Nothing is loaded; the result
     * is a query, for counting, filtering or freezing.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<Subscriber>
     *
     * @throws ValidationException for parameters its rules refuse
     */
    public function audienceSubscribers(string $key, array $parameters = []): Builder
    {
        $audience = $this->audience($key);
        $query = $audience->query(Validator::make($parameters, $audience->rules())->validate())->reorder();
        $model = $query->getModel();

        if ($model instanceof Subscriber) {
            return Subscriber::query()->whereIn('id', $query->select($model->getQualifiedKeyName()));
        }

        return Subscriber::query()
            ->where('subscribable_type', $model->getMorphClass())
            ->whereIn('subscribable_id', $query->select($model->getQualifiedKeyName()));
    }

    /**
     * @throws CampaignsException when nothing is configured under the key, or it isn't an AgentResource
     */
    public function resource(string $key): AgentResource
    {
        return $this->extension('resources', $key, AgentResource::class);
    }

    /**
     * Every configured resource, keyed by name; one that can't be resolved
     * is reported and left out.
     *
     * @return array<string, AgentResource>
     */
    public function resources(): array
    {
        return $this->extensions('resources', AgentResource::class);
    }

    /**
     * Notes that the person behind an app record (or a subscriber) did
     * something in the app, such as signing in or buying. It counts as
     * engagement, so an address that is active in the app is never treated
     * as silent, and a sunset ends the moment it is recorded (the tiers and
     * the sunset follow from the timestamps, Support\Engagement). Returns
     * false when there is no subscriber, or it already has a later activity.
     */
    public function recordActivity(Model $record, ?CarbonInterface $at = null): bool
    {
        // Never ahead of now, which would keep the person engaged and never
        // sunset, and in the app's timezone, as stored timestamps are.
        $at = $at === null || $at->greaterThan(now()) ? now() : $at->copy()->setTimezone((string) config('app.timezone'));

        $query = $record instanceof Subscriber
            ? Subscriber::query()->whereKey($record->getKey())
            : Subscriber::query()->whereMorphedTo('subscribable', $record);

        return $query
            ->where(fn (Builder $query): Builder => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $at))
            ->update(['last_active_at' => $at, 'updated_at' => now()]) > 0;
    }

    /**
     * @template TContract of object
     *
     * @param  'audiences'|'resources'  $kind
     * @param  class-string<TContract>  $contract
     * @return TContract
     */
    protected function extension(string $kind, string $key, string $contract): object
    {
        /** @var ?class-string $class */
        $class = config("campaigns.{$kind}", [])[$key] ?? null;

        if ($class === null) {
            throw CampaignsException::notConfigured($kind, $key);
        }

        $extension = app($class);

        if (! $extension instanceof $contract) {
            throw CampaignsException::invalidExtension($kind, $key, $class, $contract);
        }

        return $extension;
    }

    /**
     * @template TContract of object
     *
     * @param  'audiences'|'resources'  $kind
     * @param  class-string<TContract>  $contract
     * @return array<string, TContract>
     */
    protected function extensions(string $kind, string $contract): array
    {
        $extensions = [];

        foreach (array_keys((array) config("campaigns.{$kind}", [])) as $key) {
            try {
                $extensions[(string) $key] = $this->extension($kind, (string) $key, $contract);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $extensions;
    }
}
