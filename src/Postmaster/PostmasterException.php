<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Postmaster;

use Mozex\Campaigns\CampaignsException;

/**
 * Gmail's numbers couldn't be read. Its message names a status and Google's
 * error codes, never the request, Google's own words, the key, the signed
 * assertion or the token, and it never chains the exception behind it.
 */
class PostmasterException extends CampaignsException
{
    /**
     * One of Google's error or enum codes (PERMISSION_DENIED,
     * invalid_grant, COMPLIANT), or null for anything else, so nothing but
     * a short code from Google's answer is ever shown. The longest Google
     * documents for these reads is 34 characters.
     */
    public static function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $value) === 1 ? $value : null;
    }
}
