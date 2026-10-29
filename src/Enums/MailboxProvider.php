<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * The mailbox providers a campaign's recipients are spread across, read from
 * the domain part of the address. A business domain hosted by Google or
 * Microsoft can't be told from its name, so it counts as another provider.
 * The order of the cases is the order a campaign takes them in turn.
 */
enum MailboxProvider: string
{
    case Gmail = 'gmail';

    case Microsoft = 'microsoft';

    case Yahoo = 'yahoo';

    case Apple = 'apple';

    case Other = 'other';

    public static function fromEmail(string $email): self
    {
        $at = strrpos($email, '@');
        $domain = strtolower($at === false ? $email : substr($email, $at + 1));

        return match (true) {
            in_array($domain, ['gmail.com', 'googlemail.com'], true) => self::Gmail,
            in_array($domain, ['msn.com', 'passport.com', 'windowslive.com'], true),
            preg_match('/^(hotmail|outlook|live)\.[a-z]{2,3}(\.[a-z]{2})?$/', $domain) === 1 => self::Microsoft,
            in_array($domain, ['ymail.com', 'rocketmail.com', 'aol.com', 'aim.com'], true),
            preg_match('/^yahoo\.[a-z]{2,3}(\.[a-z]{2})?$/', $domain) === 1 => self::Yahoo,
            in_array($domain, ['icloud.com', 'me.com', 'mac.com'], true) => self::Apple,
            default => self::Other,
        };
    }

    /**
     * Its place in the turn order, from 0.
     */
    public function index(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
