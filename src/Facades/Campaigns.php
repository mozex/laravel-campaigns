<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void extend(string $name, \Closure $creator)
 * @method static string name()
 * @method static mixed withConsent(string $model, \Mozex\Campaigns\Support\ConsentContext $context, \Closure $callback)
 * @method static ?\Mozex\Campaigns\Support\ConsentContext consentFor(\Illuminate\Database\Eloquent\Model $record)
 * @method static \Mozex\Campaigns\Contracts\ListSource listSource(string $key)
 * @method static array<string, \Mozex\Campaigns\Contracts\ListSource> listSourcesFor(\Illuminate\Database\Eloquent\Model $record)
 * @method static \Mozex\Campaigns\Models\CampaignList list(string $key)
 * @method static \Mozex\Campaigns\Contracts\Audience audience(string $key)
 * @method static array<string, \Mozex\Campaigns\Contracts\Audience> audiences()
 * @method static \Illuminate\Database\Eloquent\Builder<\Mozex\Campaigns\Models\Subscriber> audienceSubscribers(string $key, array<string, mixed> $parameters = [])
 * @method static \Mozex\Campaigns\Contracts\AgentResource resource(string $key)
 * @method static array<string, \Mozex\Campaigns\Contracts\AgentResource> resources()
 * @method static bool recordActivity(\Illuminate\Database\Eloquent\Model $record, ?\Carbon\CarbonInterface $at = null)
 *
 * @see \Mozex\Campaigns\Campaigns
 */
class Campaigns extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Mozex\Campaigns\Campaigns::class;
    }
}
