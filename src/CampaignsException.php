<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Mozex\Campaigns\Contracts\CampaignProvider;
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

    public static function invalidProvider(string $value): self
    {
        return new self('campaigns.provider ("'.mb_strimwidth($value, 0, 120, '...').'") must be "mailgun", "fake" or a class implementing '.CampaignProvider::class.'.');
    }

    public static function missingWebhookSigningKey(): self
    {
        return new self('A Mailgun webhook arrived, but no webhook signing key is configured (campaigns.mailgun.webhook_signing_key, MAILGUN_WEBHOOK_SIGNING_KEY), so it can\'t be verified.');
    }

    public static function missingMailgunApiKey(): self
    {
        return new self('Reading Mailgun\'s logs and suppressions needs an API key that can read them (campaigns.mailgun.api_key, CAMPAIGNS_MAILGUN_API_KEY); the campaigns mailer\'s sending key can only send.');
    }

    /**
     * The provider couldn't be read. The message never holds the request's
     * address or the provider's answer, which can name a recipient.
     */
    public static function providerUnreadable(string $what): self
    {
        return new self($what);
    }

    public static function recipientForgotten(): self
    {
        return new self('The person this email is for was forgotten, so it can no longer be sent or shown.');
    }

    public static function testSendingDisabled(): self
    {
        return new self('Sending is switched off in this app (campaigns.enabled, CAMPAIGNS_ENABLED), so no test email can go out.');
    }

    /**
     * @param  list<string>  $held
     */
    public static function localeNotHeld(string $locale, array $held): self
    {
        return new self('The email has no "'.mb_strimwidth($locale, 0, 40, '...').'" version; it holds '.implode(', ', $held).'.');
    }

    public static function testAddressesNotConfigured(): self
    {
        return new self('No test addresses are configured (campaigns.test_addresses, CAMPAIGNS_TEST_ADDRESSES), so no test email can go out.');
    }

    /**
     * Counts the refused addresses rather than naming them: one may be a
     * subscriber's, and the message may reach an error report.
     */
    public static function notTestAddresses(int $refused): self
    {
        return new self("Test emails go only to the addresses in campaigns.test_addresses, and {$refused} of the addresses given ".($refused === 1 ? 'is' : 'are').' not among them.');
    }

    public static function tooManyTestSends(int $max): self
    {
        return new self("At most {$max} test emails go out at once: pick fewer addresses or languages.");
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownLists(array $keys): self
    {
        return new self('No list has the key '.implode(', ', array_map(fn (string $key): string => '"'.mb_strimwidth($key, 0, 130, '...').'"', $keys)).'.');
    }

    /**
     * @param  list<string>  $keys
     */
    public static function unknownCampaigns(array $keys): self
    {
        return new self('No campaign has the key '.implode(', ', array_map(fn (string $key): string => '"'.mb_strimwidth($key, 0, 130, '...').'"', $keys)).'.');
    }

    public static function invalidSetting(string $setting, string $reason): self
    {
        return new self("The campaign's {$setting} can't be used: {$reason}.");
    }

    public static function invalidPace(string $setting, string $reason): self
    {
        return new self("The pace's {$setting} can't be used: {$reason}.");
    }

    public static function invalidAudience(string $part, string $reason): self
    {
        return new self("The audience's {$part} can't be used: {$reason}.");
    }

    public static function notOnSendingDomain(string $domain): self
    {
        return new self("A campaign's From address must be on the sending domain, {$domain}.");
    }

    public static function sendingDisabled(): self
    {
        return new self('Sending is switched off in this app (campaigns.enabled, CAMPAIGNS_ENABLED), so no campaign can start, resume or speed up.');
    }

    public static function campaignCannot(string $action, string $status): self
    {
        return new self("A {$status} campaign can't be {$action}.");
    }

    public static function reportStillMoving(string $status): self
    {
        return new self("A {$status} campaign's report can't be frozen: only a completed or cancelled campaign's numbers settle.");
    }

    public static function noTargetLists(): self
    {
        return new self('The campaign has no target lists: set the lists it goes to first.');
    }

    public static function invalidRecipientCount(): self
    {
        return new self('Give the number of recipients the audience preview showed (at least 1), so a start never reaches people nobody counted.');
    }

    public static function recipientCountChanged(int $expected, int $actual): self
    {
        return new self("The audience now holds {$actual} recipients, not the {$expected} confirmed for the start. Preview it again, and start with the new number if it is still right.");
    }

    /**
     * A database error met while freezing, without the original, whose
     * message lists the SQL with its values: recipients' addresses.
     */
    public static function freezeFailed(string|int $sqlState): self
    {
        return new self("The database refused part of the campaign's freeze (SQLSTATE {$sqlState}). Start it again.");
    }

    public static function scheduleTooFar(): self
    {
        return new self('A campaign can be scheduled at most a year ahead.');
    }

    public static function paceFaster(): self
    {
        return new self('That pace sends faster than the current one (a higher number, a lifted cap, or wider or moved sending hours), so it is a speed-up, not a slow-down.');
    }

    public static function invalidFilter(string $filter, string $reason): self
    {
        return new self('The filter "'.mb_strimwidth($filter, 0, 40, '...')."\" can't be used: {$reason}.");
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

    public static function instructionsTooLong(int $length, int $limit): self
    {
        return new self("The MCP server's instructions, the package's and campaigns.mcp.instructions together, are {$length} characters long, over the {$limit} a client keeps. Shorten campaigns.mcp.instructions, and move the rest into a resource in campaigns.resources.");
    }

    public static function tokenNameTaken(string $name): self
    {
        return new self("A token named \"{$name}\" already exists. Revoke it first, or choose another name.");
    }

    public static function reservedKey(string $key): self
    {
        return new self("\"{$key}\" is the key of one of the package's starter templates. Choose another.");
    }

    public static function reservedCampaignKey(string $key): self
    {
        return new self("\"{$key}\" is the tag every test email carries, so no campaign can use it as its key. Choose another.");
    }

    public static function unknownTemplate(string $key): self
    {
        return new self('There is no template with the key "'.mb_strimwidth($key, 0, 130, '...').'".');
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

    public static function missingAppKey(): self
    {
        return new self('The app has no key (app.key, APP_KEY), which previews make their links\' tokens with.');
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

    // A signed link's refusal, likewise answered with its code.

    public static function linkInvalid(): self
    {
        return new self('This link isn\'t valid. Ask for a new one.', 403);
    }

    public static function linkExpired(): self
    {
        return new self('This link has expired. Ask for a new one.', 410);
    }

    public static function linkThrottled(int $seconds): self
    {
        return new self("Too many requests to this link. Try again in {$seconds} seconds.", 429);
    }

    public static function downloadNotFound(): self
    {
        return new self('There is no such email to download.', 404);
    }

    public static function downloadNotBuilt(): self
    {
        return new self('The download could not be built. Try again.', 500);
    }

    public static function invalidLocale(string $locale): self
    {
        return new self('"'.mb_strimwidth($locale, 0, 40, '...').'" is not a locale (en, pt_BR, zh-Hant).');
    }

    public static function unknownSubscriber(): self
    {
        return new self('No subscriber has that address.');
    }

    public static function listArchived(string $key): self
    {
        return new self("The list \"{$key}\" is archived. Restore it first.");
    }

    public static function dynamicListFixed(string $key): self
    {
        return new self("The list \"{$key}\" follows the app's records and config (campaigns.list_sources), so it can't be renamed or archived here.");
    }

    public static function listSourceKey(string $key): self
    {
        return new self("\"{$key}\" is the key of a dynamic list in campaigns.list_sources. Choose another.");
    }

    public static function tooManyContacts(int $max): self
    {
        return new self("At most {$max} contacts at a time. Send the rest in another call, or as a CSV through an import.");
    }

    public static function tooManyAddresses(int $max): self
    {
        return new self("At most {$max} addresses at a time. Send the rest in another call.");
    }

    /**
     * @param  list<string>  $problems
     */
    public static function invalidCsv(array $problems): self
    {
        return new self("The CSV can't be imported:\n- ".implode("\n- ", $problems));
    }
}
