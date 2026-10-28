<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Observers\ListSourceObserver;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * For the model behind a dynamic list (a ListSource in
 * campaigns.list_sources): its records join, update and leave their lists as
 * they are created, changed and deleted.
 *
 * @phpstan-require-extends Model
 */
trait HasCampaignSubscriber
{
    /**
     * Events, not observe(): observe() makes an instance of the model, which
     * can't happen while the model is booting.
     */
    public static function bootHasCampaignSubscriber(): void
    {
        static::created(function (Model $record): void {
            app(ListSourceObserver::class)->created($record);
        });

        static::updated(function (Model $record): void {
            app(ListSourceObserver::class)->updated($record);
        });

        static::deleted(function (Model $record): void {
            app(ListSourceObserver::class)->deleted($record);
        });
    }

    /**
     * Creates records inside the callback under the person's consent, such
     * as a sign-up form's marketing box:
     *
     *     User::withCampaignConsent(
     *         ConsentContext::fromRequest($request, 'registration', accepted: $request->boolean('news')),
     *         fn () => User::create($attributes),
     *     );
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withCampaignConsent(ConsentContext $context, Closure $callback): mixed
    {
        return app(Campaigns::class)->withConsent(static::class, $context, $callback);
    }

    /** @return MorphOne<Subscriber, $this> */
    public function campaignSubscriber(): MorphOne
    {
        return $this->morphOne(Subscriber::class, 'subscribable');
    }
}
