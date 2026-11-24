<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

/**
 * A driver whose abilities follow its configuration as well as its class:
 * one that reads its service's logs only with a key that may read them, or
 * verifies its webhooks only with their signing key. The package asks
 * before it uses an ability, and treats one whose setting is missing as one
 * the driver lacks, quietly: an optional extra left unset fails nothing. A
 * driver without this interface has every ability its class implements.
 */
interface ChecksSetup
{
    /**
     * What the configuration still lacks for one of the abilities this
     * driver implements (an interface in Contracts\Drivers), or null when
     * it is set up: the setting and its env name, written to follow "until"
     * ("campaigns.drivers.<name>.api_key (<ENV_NAME>) holds a key that may
     * read them"), never a value.
     *
     * @param  class-string  $ability
     */
    public function missingSetup(string $ability): ?string;
}
