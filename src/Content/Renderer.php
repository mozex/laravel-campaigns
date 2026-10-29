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
            $html = substr_replace($html, static::PREHEADER_BLOCK, static::bodyStart($html), 0);
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
     * Where the body starts as a browser's parser builds it, for the hidden
     * preheader: after <body>; else after </head>; else past whatever a head
     * may hold (its tags may stand without a head tag), from after <head>,
     * <html> or a leading doctype, whichever comes last, to the first text
     * or other tag. Never before a doctype, which the parser would then drop
     * (laying the email out in quirks mode), and never among the head's
     * tags, whose styles would move into the body. Comments and the text of
     * a head's elements are skipped, Outlook's conditional comments
     * included.
     */
    protected static function bodyStart(string $html): int
    {
        $blank = fn (array $match): string => str_repeat(' ', strlen($match[0]));
        $scanned = (string) preg_replace_callback('/<!--.*?-->/s', $blank, $html);
        $scanned = (string) preg_replace_callback('/<(title|style|script|noscript|template)\b[^>]*>.*?<\/\1\s*>/is', $blank, $scanned);

        $after = fn (string $pattern, int $from = 0): ?int => preg_match($pattern, $scanned, $match, PREG_OFFSET_CAPTURE, $from) === 1
            ? $match[0][1] + strlen($match[0][0])
            : null;

        $start = $after('/<body\b[^>]*>/i') ?? $after('/<\/head\s*>/i');

        if ($start !== null) {
            return $start;
        }

        $from = $after('/<head\b[^>]*>/i')
            ?? $after('/<html\b[^>]*>/i')
            ?? $after('/^(?:\xEF\xBB\xBF)?\s*<!doctype\b[^>]*>/i')
            ?? 0;

        // What a head may hold without starting the body, the elements with
        // text already blanked.
        preg_match('/\G(?:\s+|<(?:meta|link|base)\b[^>]*>)*/i', $scanned, $headStuff, 0, $from);

        return $from + strlen($headStuff[0] ?? '');
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
