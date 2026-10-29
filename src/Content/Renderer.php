<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Content;

use Illuminate\Support\Traits\Localizable;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Support\PageLocale;

/**
 * Fills an email's placeholders for one recipient. Values go into the HTML
 * escaped and into the plain text as they are; the subject and preheader
 * take only the recipient's own details, with line breaks removed, since
 * the subject becomes a mail header. The footer is the package's view in the
 * email's language, the app's own language restored afterwards.
 */
class Renderer
{
    use Localizable;

    /**
     * The hidden block a preheader goes in when the HTML doesn't place
     * {{preheader}} itself: shown by mail apps beside the subject, never in
     * the email.
     */
    protected const PREHEADER_BLOCK = '<div style="display: none; max-height: 0; max-width: 0; overflow: hidden; opacity: 0; font-size: 1px; line-height: 1px; color: transparent; mso-hide: all;">{{preheader}}</div>';

    public function render(RevisionContent|EmailContent $content, Personalization $person): RenderedEmail
    {
        $content = $content instanceof RevisionContent ? EmailContent::fromRevisionContent($content) : $content;

        return $this->withLocale(PageLocale::pick($content->locale), function () use ($content, $person): RenderedEmail {
            $preheader = $content->preheader === null || trim($content->preheader) === ''
                ? null
                : $this->line($content->preheader, $person);

            return new RenderedEmail(
                subject: $this->line($content->subject, $person),
                preheader: $preheader,
                html: $this->html($content->html, $preheader, $person),
                text: $this->text($content->text ?? PlainText::fromHtml($content->html), $preheader, $person),
            );
        });
    }

    /**
     * A subject or preheader: the recipient's details only, raw, on one line.
     */
    protected function line(string $line, Personalization $person): string
    {
        $filled = Placeholders::replace($line, fn (string $name, ?string $fallback): ?string => in_array($name, Placeholders::PERSONAL, true)
            ? $this->personal($name, $person) ?? $fallback ?? ''
            : null);

        return $this->singleLine($filled);
    }

    protected function html(string $html, ?string $preheader, Personalization $person): string
    {
        if ($preheader !== null && ! in_array('preheader', Placeholders::names($html), true)) {
            $html = preg_match('/<body\b[^>]*>/i', $html, $body, PREG_OFFSET_CAPTURE) === 1
                ? substr_replace($html, static::PREHEADER_BLOCK, $body[0][1] + strlen($body[0][0]), 0)
                : static::PREHEADER_BLOCK.$html;
        }

        return Placeholders::replace($html, function (string $name, ?string $fallback) use ($preheader, $person): ?string {
            if ($name === 'footer') {
                return $this->footer('campaigns::mail.footer', $person);
            }

            $value = $this->value($name, $preheader, $person);

            if ($value === false) {
                return null;
            }

            // A value is text, so a stored "AT&amp;T" shows as written; the
            // author's fallback is HTML, whose entities stay as they are.
            return $value !== null ? e($value) : e($fallback ?? '', false);
        });
    }

    protected function text(string $text, ?string $preheader, Personalization $person): string
    {
        return Placeholders::replace($text, function (string $name, ?string $fallback) use ($preheader, $person): ?string {
            if ($name === 'footer') {
                return trim($this->footer('campaigns::mail.footer-text', $person));
            }

            $value = $this->value($name, $preheader, $person);

            if ($value === false) {
                return null;
            }

            return $value ?? $fallback ?? '';
        });
    }

    /**
     * A placeholder's value, null when it has none (its fallback is used),
     * or false for a name that isn't a placeholder, left as written.
     */
    protected function value(string $name, ?string $preheader, Personalization $person): string|false|null
    {
        $value = match ($name) {
            'first_name', 'name', 'email' => $this->personal($name, $person),
            'unsubscribe_url', 'preferences_url' => $person->unsubscribeUrl,
            'web_version_url' => $person->webVersionUrl,
            'postal_address' => $this->configured('campaigns.postal_address'),
            'privacy_url' => $this->configured('campaigns.privacy_url'),
            'preheader' => $preheader,
            default => false,
        };

        if ($value === false) {
            return false;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    protected function personal(string $name, Personalization $person): ?string
    {
        $value = match ($name) {
            'first_name' => $person->firstName,
            'name' => $person->name,
            default => $person->email,
        };

        if ($value === null) {
            return null;
        }

        $value = $this->singleLine($value);

        return $value === '' ? null : $value;
    }

    /**
     * Control characters, line breaks among them, become spaces.
     */
    protected function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    protected function footer(string $view, Personalization $person): string
    {
        return view($view, [
            'app' => Campaigns::name(),
            'email' => $person->email,
            'unsubscribeUrl' => $person->unsubscribeUrl,
            'privacyUrl' => $this->configured('campaigns.privacy_url'),
            'postalAddress' => $this->configured('campaigns.postal_address'),
        ])->render();
    }

    /**
     * A config value trimmed, or null when it isn't text or is blank, so
     * the footer and the placeholders agree on what "none" is.
     */
    protected function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
