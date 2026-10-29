<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * Whether an IP address is one the server may fetch from: a global unicast
 * address, never one of its own networks, its cloud's metadata service or
 * anything else a request from inside could reach that the internet can't.
 */
class PublicAddress
{
    /**
     * Ranges PHP's global-range filter still lets through: multicast, and
     * the NAT64 prefixes, which a gateway translates into any IPv4 address,
     * private ones included.
     */
    protected const REFUSED = [
        '224.0.0.0/4',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        // IPv4-translated addresses (SIIT) and the old site-local range.
        '::ffff:0:0:0/96',
        'fec0::/10',
        // IPv4-compatible addresses: PHP's filter refuses ::127.0.0.1 but
        // takes the same address written ::7f00:1.
        '::/96',
    ];

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (static::REFUSED as $range) {
            if (static::inRange($address, $range)) {
                return false;
            }
        }

        return true;
    }

    protected static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);

        $address = inet_pton($address);
        $network = inet_pton($network);

        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
