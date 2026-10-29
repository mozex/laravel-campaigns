<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Sending\Suppression;
use Mozex\Campaigns\Support\EmailAddress;
use Mozex\Campaigns\Support\Sender;

/**
 * Whether the provider still holds an address on the marketing domain's
 * suppression lists, and on which. Unblocking an address here lets the
 * package mail it again, but the provider keeps dropping (and billing) mail
 * to an address on its own lists until someone removes it there, a change
 * at the provider this package never makes. Asked after UnblockAction, never
 * inside its transaction, so no row stays locked while the provider answers.
 */
class CheckProviderSuppressionsAction
{
    public function __construct(protected CampaignProvider $provider) {}

    /**
     * @return list<Suppression> empty when the provider doesn't hold it
     *
     * @throws CampaignsException when the provider can't be asked, or the address isn't valid
     */
    public function execute(string $email): array
    {
        $address = EmailAddress::normalize($email) ?? throw CampaignsException::invalidEmail();

        return $this->provider->suppressionsFor(Sender::domain(), $address);
    }
}
