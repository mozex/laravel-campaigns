<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Mozex\Campaigns\Contracts\ListSource;
use RuntimeException;

class CampaignsException extends RuntimeException
{
    public static function invalidEmail(): self
    {
        return new self('That is not an email address that can be mailed. Addresses are trimmed, lower-cased and at most 254 characters long.');
    }

    public static function addressTaken(): self
    {
        return new self('That address already belongs to another record, so it can\'t be linked to this one.');
    }

    public static function subscriberGone(): self
    {
        return new self('The subscriber was deleted before its address could change.');
    }

    public static function unknownList(string $key): self
    {
        return new self("No list source is configured for the list \"{$key}\". Add it to campaigns.list_sources.");
    }

    public static function invalidListSource(string $key, string $class): self
    {
        return new self("The list source for \"{$key}\" ({$class}) must implement ".ListSource::class.'.');
    }

    public static function dynamicList(string $key): self
    {
        return new self("The list \"{$key}\" follows the app's records, so nobody is added to it or taken off it by hand. Opt the person out, or change the record.");
    }

    public static function notDynamic(string $key): self
    {
        return new self("A static list already uses the key \"{$key}\". Give the list source in campaigns.list_sources another key.");
    }

    public static function unknownField(string $class, string $field): self
    {
        return new self("{$class}::fields() returned \"{$field}\", which a subscriber doesn't have. Use email, name, first_name, locale, timezone, country or custom_fields.");
    }

    public static function valueTooLong(string $name, int $max): self
    {
        return new self("The consent {$name} may be at most {$max} characters long.");
    }
}
