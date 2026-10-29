<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

/**
 * The only addresses a test email may go to (campaigns.test_addresses),
 * cleaned as every address is stored, so a test can't reach a subscriber
 * whatever an agent asks for.
 */
class TestAddresses
{
    /**
     * @return list<string>
     */
    public static function configured(): array
    {
        $addresses = [];

        foreach ((array) config('campaigns.test_addresses', []) as $value) {
            $address = is_string($value) ? EmailAddress::normalize($value) : null;

            if ($address !== null && ! in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public static function allows(string $address): bool
    {
        return in_array(EmailAddress::clean($address), static::configured(), true);
    }
}
