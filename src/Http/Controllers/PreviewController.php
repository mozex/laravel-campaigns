<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Content\Renderer;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\CheckSummary;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\PreviewToken;
use Mozex\Campaigns\Support\Sender;

/**
 * A revision's preview, for the people deciding whether to send it: From,
 * subject and preheader, the email at a desktop and a phone width, a tab per
 * language, the plain text and the pre-send checks' results, filled in with
 * made-up details only (never a real subscriber's, since the link lasts
 * days). Opening it changes nothing.
 *
 * The email is an agent's upload shown on the app's own origin, so it
 * renders only inside sandboxed srcdoc frames (no scripts, no same-origin),
 * and the page's policy, which a srcdoc frame inherits, allows no script at
 * all. Its links carry the revision's preview token, so the unsubscribe
 * page and the web version open as a test email's do and change nothing;
 * they open in a new tab, since the package's pages refuse to be framed.
 */
class PreviewController extends PageController
{
    protected const POLICY = "default-src 'none'; script-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; "
        ."font-src https: data:; media-src https:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; "
        .'sandbox allow-popups allow-popups-to-escape-sandbox';

    /**
     * What a frame lets the email do: open its links in a new tab, as a
     * normal page. Never scripts or same-origin.
     */
    public const FRAME_SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';

    public function __invoke(Request $request, string $revision, Renderer $renderer): Response
    {
        /** @var UrlGenerator $urls */
        $urls = app('url');

        // The language tabs add a locale to the address it was signed with.
        if (! $urls->hasCorrectSignature($request, absolute: false, ignoreQuery: ['locale'])) {
            return $this->invalid(403);
        }

        if (! $urls->signatureHasNotExpired($request)) {
            return $this->render('campaigns::invalid', [
                'heading' => __('campaigns::pages.preview.expired.heading'),
                'body' => __('campaigns::pages.preview.expired.body', ['days' => Links::PREVIEW_DAYS]),
            ], status: 410);
        }

        $found = Revision::query()->where('uuid', $revision)->with(['contents', 'frozenFrom:id,number', 'revisable'])->first();
        $owner = $found?->revisable;

        if ($found === null || ! $owner instanceof Template && ! $owner instanceof Campaign) {
            return $this->invalid();
        }

        $locale = $request->query('locale');
        $content = $found->contentFor(is_string($locale) ? $locale : null);
        $email = $renderer->render($content, Personalization::sample($content->locale, PreviewToken::shown($found, $content->locale)));

        $response = $this->render('campaigns::preview', [
            'owner' => $owner,
            'kind' => $owner instanceof Campaign ? 'campaign' : 'template',
            'revision' => $found,
            'sender' => $this->sender($owner),
            'email' => $email,
            'framed' => $this->framed($email->html),
            'sandbox' => static::FRAME_SANDBOX,
            'tabs' => $this->tabs($request, $found, $content),
            'checks' => CheckSummary::status($found),
            'failed' => array_values(array_filter(CheckSummary::checks($found), fn (array $check): bool => $check['status'] === 'failed')),
        ]);

        $response->headers->set('Content-Security-Policy', static::POLICY);

        return $response;
    }

    protected function invalid(int $status = 404): Response
    {
        return $this->render('campaigns::invalid', [], status: $status);
    }

    /**
     * Who the email comes from: a campaign's own sender, else the app's.
     *
     * @return array{name: ?string, email: ?string, reply_to: ?string}
     */
    protected function sender(Template|Campaign $owner): array
    {
        return $owner instanceof Campaign
            ? ['name' => $owner->from_name, 'email' => $owner->from_email, 'reply_to' => $owner->reply_to]
            : ['name' => Sender::name(), 'email' => Sender::address(), 'reply_to' => Sender::replyTo()];
    }

    /**
     * The email's links open in a new tab: framed, they would load the
     * page inside the preview, and most sites (the package's own pages
     * among them) refuse to be framed. The base goes inside <head>, else
     * after <html> or a doctype, never before a doctype, which would then
     * be ignored and change how the email lays out.
     */
    protected function framed(string $html): string
    {
        $base = '<base target="_blank">';

        foreach (['/<head\b[^>]*>/i', '/<html\b[^>]*>/i', '/^\s*<!doctype\b[^>]*>/i'] as $after) {
            if (preg_match($after, $html, $match, PREG_OFFSET_CAPTURE) === 1) {
                return substr_replace($html, $base, $match[0][1] + strlen($match[0][0]), 0);
            }
        }

        return $base.$html;
    }

    /**
     * A link per language: this address with its locale, which the
     * signature leaves out.
     *
     * @return list<array{locale: string, url: string, current: bool}>
     */
    protected function tabs(Request $request, Revision $revision, RevisionContent $shown): array
    {
        return $revision->contents
            ->sortBy('locale')
            ->map(fn (RevisionContent $content): array => [
                'locale' => $content->locale,
                'url' => '?'.Arr::query([...$request->query(), 'locale' => $content->locale]),
                'current' => $content->is($shown),
            ])
            ->values()
            ->all();
    }
}
