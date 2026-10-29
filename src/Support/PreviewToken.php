<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\TestSend;

/**
 * The token a preview's links carry: one per revision and language, made
 * from the revision's uuid with the app's key, so the preview page makes it
 * again on every view while no raw token is stored or put in the page's
 * address. Its hash is a test email row's (Actions\CreatePreviewLinkAction),
 * so the links open the real pages and change nothing, as a test email's do.
 */
class PreviewToken
{
    /**
     * The token made with the app's key, or the key given.
     *
     * @throws CampaignsException when the app has no key
     */
    public static function for(Revision $revision, string $locale, ?string $key = null): string
    {
        $key ??= config('app.key');

        if (! is_string($key) || trim($key) === '') {
            throw CampaignsException::missingAppKey();
        }

        return hash_hmac('sha256', 'campaigns-preview|'.$revision->uuid.'|'.$locale, $key);
    }

    /**
     * The token a preview page shows: the app key's, else that of a previous
     * key (app.previous_keys) whose row a link made before the key changed,
     * since the page's signature still passes with those keys. Only reads.
     *
     * @throws CampaignsException when the app has no key
     */
    public static function shown(Revision $revision, string $locale): string
    {
        $current = static::for($revision, $locale);

        if (static::hasRow($current)) {
            return $current;
        }

        foreach ((array) config('app.previous_keys', []) as $key) {
            if (is_string($key) && trim($key) !== '' && static::hasRow($token = static::for($revision, $locale, $key))) {
                return $token;
            }
        }

        return $current;
    }

    protected static function hasRow(string $token): bool
    {
        return TestSend::query()->where('token_hash', Token::hash($token))->exists();
    }
}
