<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\Token;

/**
 * What one recipient's email is filled in with. {{unsubscribe_url}} and
 * {{preferences_url}} both take the one unsubscribe address, since one page
 * serves both, so they can never lead to different places.
 */
readonly class Personalization
{
    public function __construct(
        public string $email,
        public string $unsubscribeUrl,
        public ?string $firstName = null,
        public ?string $name = null,
        public ?string $webVersionUrl = null,
        public ?string $locale = null,
    ) {}

    /**
     * The links made from the token of the email that carries them.
     */
    public static function forToken(
        string $token,
        string $email,
        ?string $firstName = null,
        ?string $name = null,
        ?string $webVersionUrl = null,
        ?string $locale = null,
    ): self {
        return new self(
            email: $email,
            unsubscribeUrl: Links::unsubscribe($token),
            firstName: $firstName,
            name: $name,
            webVersionUrl: $webVersionUrl,
            locale: $locale,
        );
    }

    /**
     * Made-up values, as long as real ones, for previews and for measuring
     * an email's size: never a real subscriber's.
     */
    public static function sample(?string $locale = null): self
    {
        $token = str_repeat('x', strlen(Token::generate()));

        return self::forToken(
            token: $token,
            email: 'someone@example.com',
            firstName: 'Alexandra',
            name: 'Alexandra Example',
            webVersionUrl: Links::unsubscribe($token),
            locale: $locale,
        );
    }
}
