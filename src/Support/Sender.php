<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Mozex\Campaigns\CampaignsException;

/**
 * Who campaigns come from, read from the config: the package's own sender
 * (campaigns.mail.from), else the campaigns mailer's, else the app's. A
 * campaign's From address must be on the marketing domain, so its
 * reputation stays apart from the app's account emails.
 */
class Sender
{
    public static function mailer(): string
    {
        return (string) config('campaigns.mail.mailer');
    }

    public static function address(): ?string
    {
        return static::first('address');
    }

    public static function name(): ?string
    {
        return static::first('name');
    }

    public static function replyTo(): ?string
    {
        return static::configured('campaigns.mail.reply_to');
    }

    /**
     * The marketing domain: campaigns.mail.domain, else the sender's domain.
     *
     * @throws CampaignsException when neither is set
     */
    public static function domain(): string
    {
        $domain = static::configured('campaigns.mail.domain');

        if ($domain !== null) {
            return strtolower($domain);
        }

        $address = static::address();

        if ($address === null || ! str_contains($address, '@')) {
            throw CampaignsException::noSendingDomain();
        }

        return strtolower(substr($address, strrpos($address, '@') + 1));
    }

    /**
     * Whether an address is on the marketing domain itself (a subdomain of
     * it is a different domain to mailbox providers).
     */
    public static function isOnDomain(string $email): bool
    {
        $at = strrpos($email, '@');

        return $at !== false && strtolower(substr($email, $at + 1)) === static::domain();
    }

    /**
     * @param  'address'|'name'  $key
     */
    protected static function first(string $key): ?string
    {
        return static::configured('campaigns.mail.from.'.$key)
            ?? static::configured('mail.mailers.'.static::mailer().'.from.'.$key)
            ?? static::configured('mail.from.'.$key);
    }

    /**
     * A config value trimmed, or null when it isn't text or is blank.
     */
    protected static function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
