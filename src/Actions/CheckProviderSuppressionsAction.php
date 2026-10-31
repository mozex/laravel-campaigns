<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Sender;

/**
 * Whether the active driver's service still holds an address on the
 * marketing domain's suppression lists, and on which. Unblocking an address
 * here lets the package mail it again, but the service keeps dropping (and
 * billing) mail to an address on its own lists until someone removes it
 * there, a change at the service this package never makes. Asked after
 * UnblockAction, never inside its transaction, so no row stays locked while
 * the service answers.
 */
class CheckProviderSuppressionsAction
{
    public function __construct(protected DriverManager $drivers) {}

    /**
     * @return list<Suppression> empty when the service doesn't hold it
     *
     * @throws CampaignsException when the service can't be asked (the driver reads no lists, or they can't be read), or the address isn't valid
     */
    public function execute(string $email): array
    {
        $address = EmailAddress::normalize($email) ?? throw CampaignsException::invalidEmail();
        $driver = $this->drivers->active();

        if (! $driver instanceof ReadsSuppressions) {
            throw CampaignsException::driverLacks($this->drivers->getDefaultDriver(), 'reads no suppression lists, so whether its service still holds the address can\'t be checked');
        }

        return $driver->suppressionsFor(Sender::domain(), $address);
    }
}
