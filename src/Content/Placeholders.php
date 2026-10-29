<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Closure;

/**
 * The fixed set of {{name}} placeholders an email may hold, each with an
 * optional fallback ({{first_name|there}}). They are replaced as plain
 * strings in one pass, never compiled as a template, so an uploaded email
 * can't run code and a value put in is never read as a placeholder again.
 */
class Placeholders
{
    /**
     * The recipient's own details, the only ones a subject or preheader may
     * hold.
     */
    public const PERSONAL = ['first_name', 'name', 'email'];

    public const ALL = [
        'first_name',
        'name',
        'email',
        'unsubscribe_url',
        'preferences_url',
        'web_version_url',
        'footer',
        'postal_address',
        'privacy_url',
        'preheader',
    ];

    /**
     * What each placeholder becomes, as agents are told.
     */
    public const DESCRIPTIONS = [
        'first_name' => 'The recipient\'s first name. Give a fallback for anyone without one: {{first_name|there}}.',
        'name' => 'The recipient\'s full name.',
        'email' => 'The recipient\'s email address.',
        'unsubscribe_url' => 'The page where the recipient unsubscribes from all of this app\'s email, or subscribes again.',
        'preferences_url' => 'The same page as unsubscribe_url.',
        'web_version_url' => 'The email as a web page, for a "view in your browser" link.',
        'footer' => 'Who the email went to, the unsubscribe and privacy links and the postal address, in the email\'s language.',
        'postal_address' => 'The sender\'s postal address (campaigns.postal_address).',
        'privacy_url' => 'The privacy policy\'s address (campaigns.privacy_url).',
        'preheader' => 'Where the preheader goes. Without it, the preheader goes in a hidden block right after <body>.',
    ];

    /**
     * Placeholders that give an unsubscribe link: the address itself, or the
     * footer, which carries it.
     */
    public const UNSUBSCRIBE = ['unsubscribe_url', 'preferences_url', 'footer'];

    protected const PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:\|([^{}]*?))?\s*\}\}/';

    /**
     * The placeholder names a string holds, in order, repeats kept.
     *
     * @return list<string>
     */
    public static function names(string $subject): array
    {
        preg_match_all(static::PATTERN, $subject, $matches);

        return array_map(fn (string $name): string => strtolower($name), $matches[1]);
    }

    /**
     * What looks like a placeholder but can't be one ({{first-name}},
     * {{ $name }}), which would reach the inbox as written.
     *
     * @return list<string>
     */
    public static function malformed(string $subject): array
    {
        preg_match_all('/\{\{[^{}]*\}\}/', $subject, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $candidate): bool => preg_match(static::PATTERN, $candidate, $match) !== 1 || $match[0] !== $candidate,
        ));
    }

    /**
     * Replaces every placeholder with what the resolver returns for its name
     * and fallback, in one pass; null leaves the placeholder as written.
     *
     * @param  Closure(string, ?string): ?string  $resolve
     */
    public static function replace(string $subject, Closure $resolve): string
    {
        return (string) preg_replace_callback(
            static::PATTERN,
            fn (array $match): string => $resolve(strtolower($match[1]), isset($match[2]) ? trim($match[2]) : null) ?? $match[0],
            $subject,
        );
    }
}
