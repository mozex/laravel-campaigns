<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Mozex\Campaigns\CampaignsException;

/**
 * What every revision of a template or a campaign's email must meet before it
 * is stored: the default language present; a subject on one line; no
 * placeholder the package doesn't know (a typo would reach every inbox as
 * written); an unsubscribe link in the HTML and in the plain text alike
 * ({{unsubscribe_url}}, or {{footer}}, which carries it); every image on
 * https; elements nested at most Html::MAX_DEPTH deep, so every rule reads
 * the whole email; and at most 90 KB of HTML once filled in for a
 * recipient, since Gmail clips an email past 102 KB and click tracking
 * lengthens every link; and at most MAX_LOCALES languages, so an agent's
 * answer about one email or a page of them stays short. Every problem is
 * reported at once.
 */
class ContentRules
{
    public const MAX_HTML_BYTES = 90 * 1024;

    public const MAX_LINE_LENGTH = 255;

    public const MAX_LOCALES = 20;

    public function __construct(protected Renderer $renderer) {}

    /**
     * The contents ready to store, each with its plain text (generated from
     * the HTML when none was given), or every reason they can't be.
     *
     * @param  list<EmailContent>  $contents
     * @return list<EmailContent>
     *
     * @throws CampaignsException
     */
    public function prepare(array $contents): array
    {
        if ($contents === []) {
            throw CampaignsException::invalidContent(['There is no content.']);
        }

        $problems = [];
        $prepared = [];
        $seen = [];

        if (count($contents) > static::MAX_LOCALES) {
            $problems[] = 'There are '.count($contents).' languages; an email holds at most '.static::MAX_LOCALES.'.';
        }

        foreach ($contents as $content) {
            $locale = $content->locale;

            if (! Locales::isValid($locale)) {
                $problems[] = '"'.mb_strimwidth($locale, 0, 40, '...').'" is not a locale (en, pt_BR, zh-Hant).';

                continue;
            }

            // en and EN, or pt-BR and pt_BR, are one language, and MySQL's
            // unique index would say so with the whole email in its error.
            $language = strtolower(str_replace('-', '_', $locale));

            if (isset($seen[$language])) {
                $problems[] = "{$locale}: given twice.";

                continue;
            }

            $seen[$language] = true;
            $prepared[$locale] = $this->prepareOne($content, $problems);
        }

        // However a version's locale is written (EN, en-US against en_us),
        // as every other locale lookup compares them.
        if ($prepared !== [] && ! in_array(Locales::normalize(Locales::default()), array_map(Locales::normalize(...), array_keys($prepared)), true)) {
            $problems[] = 'There is no '.Locales::default().' version: every email holds the default language, which recipients whose language it lacks get.';
        }

        if ($problems !== []) {
            throw CampaignsException::invalidContent($problems);
        }

        return array_values($prepared);
    }

    /**
     * @param  list<string>  $problems
     */
    protected function prepareOne(EmailContent $content, array &$problems): EmailContent
    {
        $locale = $content->locale;
        $subject = trim($content->subject);
        $preheader = $content->preheader === null || trim($content->preheader) === '' ? null : trim($content->preheader);
        $given = $content->text === null || trim($content->text) === '' ? null : $content->text;

        foreach (['subject' => $subject, 'preheader' => $preheader, 'HTML' => $content->html, 'text' => $given] as $part => $value) {
            if ($value !== null && (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                $problems[] = "{$locale}: the {$part} isn't UTF-8 text.";

                return $content;
            }
        }

        if ($subject === '') {
            $problems[] = "{$locale}: the subject is empty.";
        } elseif (preg_match('/[\r\n]/', $subject) === 1) {
            $problems[] = "{$locale}: the subject must fit on one line.";
        }

        foreach (['subject' => $subject, 'preheader' => $preheader] as $part => $value) {
            if ($value !== null && mb_strlen($value) > static::MAX_LINE_LENGTH) {
                $problems[] = "{$locale}: the {$part} is longer than ".static::MAX_LINE_LENGTH.' characters.';
            }

            $others = array_diff(Placeholders::names((string) $value), Placeholders::PERSONAL);

            if ($others !== []) {
                $problems[] = "{$locale}: the {$part} may hold only {{first_name}}, {{name}} and {{email}}, not ".static::listed($others).'.';
            }

            static::notPlaceholders($locale, $part, Placeholders::malformed((string) $value), $problems);
        }

        if (trim($content->html) === '') {
            $problems[] = "{$locale}: the HTML is empty.";

            return $content;
        }

        if ($given !== null && strlen($given) > static::MAX_HTML_BYTES) {
            $problems[] = "{$locale}: the plain text is over 90 KB.";
        }

        $text = $given ?? PlainText::fromHtml($content->html);

        foreach (['HTML' => $content->html, 'plain text' => $given] as $part => $value) {
            if ($value !== null) {
                $unknown = array_map(
                    fn (string $name): string => '{{'.$name.'}}',
                    array_diff(Placeholders::names($value), Placeholders::ALL),
                );

                static::notPlaceholders($locale, $part, [...$unknown, ...Placeholders::malformed($value)], $problems);
            }
        }

        // A tool that re-serializes HTML through libxml (a CSS inliner, say)
        // percent-encodes the braces in href and src, so the placeholder
        // would go out as a dead link.
        if (preg_match_all('/%7B%7B\s*([A-Za-z_][A-Za-z0-9_]*)[^<>"\'\s]*?%7D%7D/i', $content->html, $encoded) > 0) {
            $problems[] = "{$locale}: the HTML holds ".implode(', ', array_unique($encoded[0])).', a placeholder whose braces a tool percent-encoded; write it as {{'.$encoded[1][0].'}}.';
        }

        foreach (['HTML' => $content->html, 'plain text' => $text] as $part => $value) {
            if (array_intersect(Placeholders::names($value), Placeholders::UNSUBSCRIBE) === []) {
                $problems[] = "{$locale}: the {$part} has no unsubscribe link. Add {{unsubscribe_url}}, or {{footer}}, which carries it.";
            }
        }

        if (Html::nestsTooDeep($content->html)) {
            $problems[] = "{$locale}: the HTML nests elements more than ".Html::MAX_DEPTH.' deep, past what these rules can read (the parser stops at 256); flatten its nesting.';
        }

        foreach (array_unique(Html::imageUrls($content->html)) as $url) {
            if (! str_starts_with(strtolower($url), 'https://')) {
                $image = $url === '' ? 'with no address' : '"'.mb_strimwidth($url, 0, 120, '...').'"';

                $problems[] = "{$locale}: the image {$image} is not on https.";
            }
        }

        $prepared = new EmailContent(
            locale: $locale,
            subject: $subject,
            html: $content->html,
            text: $text,
            preheader: $preheader,
            textGenerated: $given === null,
        );

        // Refused unrendered when it is twice the limit as written, so an
        // upload of up to a megabyte never costs a render; nearer the limit,
        // filling in can shrink it (a fallback longer than a sample name).
        $unrendered = strlen($content->html) > 2 * static::MAX_HTML_BYTES;
        $size = $unrendered ? strlen($content->html) : strlen($this->renderer->render($prepared, Personalization::sample($locale))->html);

        if ($size > static::MAX_HTML_BYTES) {
            $problems[] = "{$locale}: the HTML is ".round($size / 1024).' KB '.($unrendered ? 'as written' : 'once filled in').', over the 90 KB limit (Gmail clips an email past 102 KB, and click tracking lengthens every link).';
        }

        return $prepared;
    }

    /**
     * @param  list<string>  $found  as written in the email
     * @param  list<string>  $problems
     */
    protected static function notPlaceholders(string $locale, string $part, array $found, array &$problems): void
    {
        $found = array_values(array_unique($found));

        if ($found === []) {
            return;
        }

        $problems[] = "{$locale}: the {$part} holds ".implode(', ', array_map(fn (string $text): string => mb_strimwidth($text, 0, 60, '...'), $found))
            .', which '.(count($found) === 1 ? 'is not a placeholder' : 'are not placeholders')
            .'. The placeholders are '.static::listed(Placeholders::ALL).'.';
    }

    /**
     * @param  array<array-key, string>  $names
     */
    protected static function listed(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => '{{'.$name.'}}', array_values(array_unique($names))));
    }
}
