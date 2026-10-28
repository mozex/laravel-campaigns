<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Support\Str;

/**
 * The secrets behind the links in an email. Only a token's hash is stored, so
 * the raw token exists only in the email that carries it: it is made where
 * the email is sent, never earlier, so it never sits in a queued job.
 */
class Token
{
    public static function generate(): string
    {
        return Str::random(48);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
