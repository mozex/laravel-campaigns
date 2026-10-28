<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed withConsent(string $model, \Mozex\Campaigns\Support\ConsentContext $context, \Closure $callback)
 * @method static ?\Mozex\Campaigns\Support\ConsentContext consentFor(\Illuminate\Database\Eloquent\Model $record)
 * @method static array<string, \Mozex\Campaigns\Contracts\ListSource> listSources()
 * @method static \Mozex\Campaigns\Contracts\ListSource listSource(string $key)
 * @method static array<string, \Mozex\Campaigns\Contracts\ListSource> listSourcesFor(\Illuminate\Database\Eloquent\Model $record)
 * @method static \Mozex\Campaigns\Models\CampaignList list(string $key)
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
