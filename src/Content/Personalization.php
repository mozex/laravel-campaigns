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
    /**
     * The made-up recipient previews and sizes are rendered for, on a domain
     * no one can own.
     */
    public const SAMPLE_EMAIL = 'someone@example.com';

    public const SAMPLE_FIRST_NAME = 'Alexandra';

    public const SAMPLE_NAME = 'Alexandra Example';

    public function __construct(
        public string $email,
        public string $unsubscribeUrl,
        public ?string $firstName = null,
        public ?string $name = null,
        public ?string $webVersionUrl = null,
        public ?string $locale = null,
    ) {}

    /**
     * The links made from the token of the email that carries them: the
     * unsubscribe page and, unless another is given, the web version.
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
            webVersionUrl: $webVersionUrl ?? Links::webVersion($token),
            locale: $locale,
        );
    }

    /**
     * Made-up values, as long as real ones, for previews and for measuring
     * an email's size: never a real subscriber's. A preview passes the token
     * its links open (Support\PreviewToken); without one they lead nowhere.
     */
    public static function sample(?string $locale = null, ?string $token = null): self
    {
        return self::forToken(
            token: $token ?? str_repeat('x', strlen(Token::generate())),
            email: self::SAMPLE_EMAIL,
            firstName: self::SAMPLE_FIRST_NAME,
            name: self::SAMPLE_NAME,
            locale: $locale,
        );
    }
}
