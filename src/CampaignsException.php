<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Mozex\Campaigns\Contracts\ListSource;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Imports\ImportedContact;
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

    public static function unknownImportField(string $field): self
    {
        return new self("A contact has no field \"{$field}\". Use ".implode(', ', ImportedContact::FIELDS).'.');
    }

    public static function invalidImportValue(string $field, string $reason): self
    {
        return new self("The contact's {$field} can't be imported: {$reason}.");
    }

    /**
     * @param  'audiences'|'resources'  $kind
     */
    public static function notConfigured(string $kind, string $key): self
    {
        return new self("Nothing is configured as \"{$key}\" in campaigns.{$kind}.");
    }

    /**
     * @param  'audiences'|'resources'  $kind
     * @param  class-string  $contract
     */
    public static function invalidExtension(string $kind, string $key, string $class, string $contract): self
    {
        return new self("campaigns.{$kind}.{$key} ({$class}) must implement {$contract}.");
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownLists(array $keys): self
    {
        return new self('No list has the key '.implode(', ', array_map(fn (string $key): string => "\"{$key}\"", $keys)).'.');
    }

    public static function invalidFilter(string $filter, string $reason): self
    {
        return new self("The filter \"{$filter}\" can't be used: {$reason}.");
    }

    /**
     * @param  list<string>  $problems
     */
    public static function invalidContent(array $problems): self
    {
        return new self("The email can't be saved:\n- ".implode("\n- ", $problems));
    }

    public static function invalidKey(string $key): self
    {
        return new self('"'.mb_strimwidth($key, 0, 40, '...').'" can\'t be a key: use lower-case letters, digits, dots, dashes and underscores, starting with a letter or digit, at most 125 characters.');
    }

    public static function keyTaken(string $kind, string $key): self
    {
        return new self("A {$kind} with the key \"{$key}\" already exists.");
    }

    public static function reservedKey(string $key): self
    {
        return new self("\"{$key}\" is the key of one of the package's starter templates. Choose another.");
    }

    public static function unknownTemplate(string $key): self
    {
        return new self("There is no template with the key \"{$key}\".");
    }

    public static function invalidValue(string $field, string $reason): self
    {
        return new self("The {$field} {$reason}.");
    }

    public static function campaignNotDraft(string $status): self
    {
        return new self("The campaign is {$status}, and only a draft's email can change. Duplicate it to make a new draft.");
    }

    public static function noRevision(): self
    {
        return new self('It has no email yet.');
    }

    public static function noSendingDomain(): self
    {
        return new self('No sending domain is configured: set campaigns.mail.domain (CAMPAIGNS_DOMAIN), or a sender address in campaigns.mail.from.address or the campaigns mailer.');
    }

    /**
     * An address the server won't fetch from (not on the web, or leading
     * into a private network) or couldn't.
     */
    public static function cannotFetch(string $url, string $reason): self
    {
        return new self('"'.mb_strimwidth($url, 0, 200, '...')."\" {$reason}.");
    }

    public static function invalidImage(string $name, string $reason): self
    {
        return new self('The image "'.mb_strimwidth($name, 0, 120, '...')."\" {$reason}.");
    }

    public static function assetDiskNotHttps(string $disk): self
    {
        return new self("The asset disk \"{$disk}\" doesn't give https addresses, which emails need: set its url to an https address (campaigns.assets.disk names it).");
    }

    public static function assetNotStored(string $name): self
    {
        return new self('The image "'.mb_strimwidth($name, 0, 120, '...').'" could not be stored on the asset disk.');
    }

    // An upload refusal's code is the HTTP status the upload endpoint
    // answers with.

    public static function uploadNotFound(): self
    {
        return new self('There is no upload with that id.', 404);
    }

    public static function uploadExpired(): self
    {
        return new self('The upload link has expired. Ask for a new one.', 410);
    }

    public static function uploadUnavailable(string $reason): self
    {
        return new self("The upload can't be used: {$reason}. Ask for a new link.", 409);
    }

    public static function uploadTooLarge(int $max): self
    {
        return new self('The file is over the upload limit of '.round($max / 1024 / 1024, 1).' MB.', 413);
    }

    public static function uploadEmpty(): self
    {
        return new self('The file is empty.', 400);
    }

    public static function uploadNotStored(): self
    {
        return new self('The file could not be stored. Try again.', 500);
    }

    public static function uploadWrongPurpose(UploadPurpose $expected, UploadPurpose $actual): self
    {
        return new self("That upload is for {$actual->label()}, not {$expected->label()}.", 422);
    }

    /**
     * @param  list<string>  $problems
     */
    public static function invalidUpload(array $problems): self
    {
        return new self("The upload can't be used:\n- ".implode("\n- ", $problems), 422);
    }

    public static function invalidLocale(string $locale): self
    {
        return new self('"'.mb_strimwidth($locale, 0, 40, '...').'" is not a locale (en, pt_BR, zh-Hant).');
    }
}
