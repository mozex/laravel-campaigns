<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Templates;

use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\Locales;

/**
 * The templates the package ships: read-only files an agent lists beside the
 * app's own templates and duplicates to start from. They aren't rows, so a
 * package update changes them without touching an app's templates.
 */
class StarterTemplates
{
    /**
     * @return list<StarterTemplate>
     */
    public static function all(): array
    {
        return [
            new StarterTemplate(
                key: 'starter',
                name: 'Starter',
                description: 'A plain, single-column email that renders in every mail app, Outlook included: a headline, a few paragraphs, one button and the footer.',
                contents: [
                    new EmailContent(
                        locale: Locales::default(),
                        subject: 'A headline that says what this email is about',
                        html: (string) file_get_contents(__DIR__.'/../../resources/templates/starter.html'),
                        preheader: 'One line that adds to the subject: mail apps show it beside the subject.',
                    ),
                ],
            ),
        ];
    }

    public static function find(string $key): ?StarterTemplate
    {
        foreach (static::all() as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        return null;
    }

    public static function has(string $key): bool
    {
        return static::find($key) !== null;
    }
}
