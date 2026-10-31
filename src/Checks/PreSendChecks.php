<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Checks;

use DOMElement;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Content\Html;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Content\Personalization;
use Mozex\Campaigns\Content\Placeholders;
use Mozex\Campaigns\Content\RenderedEmail;
use Mozex\Campaigns\Content\Renderer;
use Mozex\Campaigns\Enums\CheckStatus;
use Mozex\Campaigns\Fetching\Fetcher;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Models\RevisionContent;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Support\Sender;

/**
 * What is checked on an email before it is sent, each locale filled in
 * with sample values: every target language present; an unsubscribe link
 * in the HTML and the text; a link to the privacy policy; the HTML's size
 * once click tracking lengthens its links; images without alt text or not
 * on https; every link and image fetched from the server through the
 * public-address guard; and, where switched on, a spam score from
 * Postmark's free SpamAssassin checker. The results report; none of them
 * blocks a send.
 */
class PreSendChecks
{
    /**
     * Every check a run writes, by its name in the results.
     */
    public const CHECKS = ['locales', 'unsubscribe', 'privacy_link', 'size', 'images', 'links', 'spam_score'];

    /**
     * A conservative length for a click-tracking address, which replaces
     * every link in the HTML.
     */
    public const TRACKED_LINK_BYTES = 400;

    public const MAX_LINKS = 100;

    /** How long all the link checks together may take. */
    public const LINK_SECONDS = 120;

    /**
     * How long the whole run may take, the spam scores included, under
     * RunChecksJob's timeout.
     */
    public const RUN_SECONDS = 240;

    /** SpamAssassin's own line: a score at or over it is spam. */
    public const SPAM_THRESHOLD = 5.0;

    public const SPAM_CHECKER = 'https://spamcheck.postmarkapp.com/filter';

    protected const SPAM_TIMEOUT = 30;

    public function __construct(
        protected Renderer $renderer,
        protected Fetcher $fetcher,
    ) {}

    /**
     * @param  ?list<string>  $locales  the languages the recipients speak, when known
     * @param  bool  $clickTracking  whether the provider will rewrite the links
     * @return list<array<string, mixed>> one result per check (and per locale where it applies)
     */
    public function run(Revision $revision, ?array $locales = null, bool $clickTracking = true): array
    {
        $started = microtime(true);
        $contents = $revision->contents()->orderBy('id')->get();
        $rendered = [];
        $results = [$this->locales($contents->pluck('locale')->all(), $locales)];

        foreach ($contents as $content) {
            /** @var RevisionContent $content */
            $email = $this->renderer->render($content, Personalization::sample($content->locale));
            $rendered[$content->locale] = $email;

            $results[] = $this->unsubscribe($content);
            $results[] = $this->privacyLink($content);
            $results[] = $this->size($content->locale, $email, $clickTracking);
            $results[] = $this->images($content->locale, $email);
        }

        $results[] = $this->links($rendered, $started + static::LINK_SECONDS);

        foreach ($rendered as $locale => $email) {
            $results[] = $this->spamScore($revision, (string) $locale, $email, $started + static::RUN_SECONDS);
        }

        return $results;
    }

    /**
     * @param  list<string>  $held
     * @param  ?list<string>  $targets
     * @return array<string, mixed>
     */
    protected function locales(array $held, ?array $targets): array
    {
        sort($held);
        $holds = 'It holds '.implode(', ', $held).'.';

        if ($targets === null) {
            return $this->result('locales', null, CheckStatus::Passed, "{$holds} No audience was given to compare with.");
        }

        $normalized = array_map(static::language(...), $held);
        $missing = array_values(array_unique(array_filter($targets, fn (string $target): bool => ! in_array(static::language($target), $normalized, true))));

        if ($missing === []) {
            return $this->result('locales', null, CheckStatus::Passed, "{$holds} Every language the recipients speak has its own version.");
        }

        return $this->result('locales', null, CheckStatus::Failed, "{$holds} There is no version in ".implode(', ', $missing).': those recipients get the '.Locales::default().' version.', [
            'missing' => $missing,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function unsubscribe(RevisionContent $content): array
    {
        $missing = [];

        foreach (['HTML' => $content->html, 'plain text' => $content->text] as $part => $value) {
            if (array_intersect(Placeholders::names($value), Placeholders::UNSUBSCRIBE) === []) {
                $missing[] = $part;
            }
        }

        return $missing === []
            ? $this->result('unsubscribe', $content->locale, CheckStatus::Passed, 'The HTML and the plain text each carry an unsubscribe link.')
            : $this->result('unsubscribe', $content->locale, CheckStatus::Failed, 'The '.implode(' and the ', $missing).' carry no unsubscribe link: add {{unsubscribe_url}} or {{footer}}.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function privacyLink(RevisionContent $content): array
    {
        $url = config('campaigns.privacy_url');

        if (! is_string($url) || trim($url) === '') {
            return $this->result('privacy_link', $content->locale, CheckStatus::Failed, 'campaigns.privacy_url is not set, so the email links no privacy policy: mailbox providers and sending services ask for that link in every email.');
        }

        $names = Placeholders::names($content->html);

        if (in_array('footer', $names, true) || in_array('privacy_url', $names, true) || str_contains($content->html, trim($url))) {
            return $this->result('privacy_link', $content->locale, CheckStatus::Passed, 'The HTML links the privacy policy.');
        }

        return $this->result('privacy_link', $content->locale, CheckStatus::Failed, 'The HTML doesn\'t link the privacy policy: add {{footer}} or {{privacy_url}}.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function size(string $locale, RenderedEmail $email, bool $clickTracking): array
    {
        $bytes = strlen($email->html);
        $growth = 0;

        if ($clickTracking) {
            foreach ($this->anchors($email->html) as $href) {
                $growth += max(0, static::TRACKED_LINK_BYTES - strlen($href));
            }
        }

        $total = $bytes + $growth;
        $size = round($total / 1024).' KB'.($clickTracking ? ' once click tracking lengthens its links' : '');

        return $total > ContentRules::MAX_HTML_BYTES
            ? $this->result('size', $locale, CheckStatus::Failed, "The HTML is about {$size}, over the 90 KB limit: Gmail clips an email past 102 KB.", ['bytes' => $total])
            : $this->result('size', $locale, CheckStatus::Passed, "The HTML is about {$size}, within the 90 KB limit.", ['bytes' => $total]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function images(string $locale, RenderedEmail $email): array
    {
        $items = [];

        foreach (Html::parse($email->html)->getElementsByTagName('img') as $image) {
            /** @var DOMElement $image */
            // An empty alt marks a decorative image, which screen readers
            // skip; only a missing one is a problem.
            if (! $image->hasAttribute('alt')) {
                $items[] = ['url' => $image->getAttribute('src'), 'problem' => 'has no alt text'];
            }
        }

        foreach (array_unique(Html::imageUrls($email->html)) as $url) {
            if (! str_starts_with(strtolower($url), 'https://')) {
                $items[] = ['url' => $url, 'problem' => 'is not on https'];
            }
        }

        return $items === []
            ? $this->result('images', $locale, CheckStatus::Passed, 'Every image has alt text and is on https.')
            : $this->result('images', $locale, CheckStatus::Failed, count($items).' image problem'.(count($items) === 1 ? '' : 's').'.', ['items' => $items]);
    }

    /**
     * Every link and image, each address fetched once whatever number of
     * locales hold it, with a GET stopped once its headers arrive: what a
     * browser asks for, without reading the page (some servers refuse
     * HEAD). The package's own pages are left out, since a sample's links
     * lead nowhere.
     *
     * @param  array<string, RenderedEmail>  $rendered
     * @return array<string, mixed>
     */
    protected function links(array $rendered, float $deadline): array
    {
        $own = rtrim((string) (config('campaigns.url') ?: config('app.url')), '/').'/campaigns/';
        $addresses = [];

        foreach ($rendered as $locale => $email) {
            foreach ([...$this->anchors($email->html), ...Html::imageUrls($email->html)] as $url) {
                if (preg_match('#^https?://#i', $url) === 1 && ! str_starts_with($url, $own)) {
                    $addresses[$url][] = (string) $locale;
                }
            }
        }

        $items = [];
        $checked = 0;

        foreach ($addresses as $url => $locales) {
            $url = (string) $url;
            $locales = array_values(array_unique($locales));

            if ($checked === static::MAX_LINKS || microtime(true) >= $deadline) {
                $items[] = ['url' => $url, 'problem' => 'not checked: the checks stop after '.static::MAX_LINKS.' addresses or '.static::LINK_SECONDS.' seconds', 'locales' => $locales];

                continue;
            }

            $checked++;
            $problem = $this->linkProblem($url, $deadline);

            if (str_starts_with(strtolower($url), 'http://')) {
                $problem = $problem === null ? 'uses http:, not https:' : "uses http:, not https:, and {$problem}";
            }

            if ($problem !== null) {
                $items[] = ['url' => $url, 'problem' => $problem, 'locales' => $locales];
            }
        }

        $plural = count($addresses) === 1 ? '' : 's';
        $count = count($addresses)." link{$plural} and image{$plural}";

        return $items === []
            ? $this->result('links', null, CheckStatus::Passed, "All {$count} answered.", ['checked' => $checked])
            : $this->result('links', null, CheckStatus::Failed, count($items)." of the {$count} have a problem.", ['checked' => $checked, 'items' => $items]);
    }

    protected function linkProblem(string $url, float $deadline): ?string
    {
        try {
            $response = $this->fetcher->fetch($url, 0, httpsOnly: false, headersOnly: true, deadline: $deadline);
        } catch (CampaignsException $exception) {
            return $exception->getMessage();
        }

        return $response->successful() ? null : "answered {$response->status}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function spamScore(Revision $revision, string $locale, RenderedEmail $email, float $deadline): array
    {
        if (! config('campaigns.checks.spam_score')) {
            return $this->result('spam_score', $locale, CheckStatus::Skipped, 'Switched off (campaigns.checks.spam_score).');
        }

        $timeout = (int) min(static::SPAM_TIMEOUT, floor($deadline - microtime(true)));

        if ($timeout < 1) {
            return $this->result('spam_score', $locale, CheckStatus::Skipped, 'Not scored: the time for checking ran out.');
        }

        try {
            $raw = $this->message($revision, $email, Personalization::sample($locale));
        } catch (CampaignsException|InvalidArgumentException $exception) {
            return $this->result('spam_score', $locale, CheckStatus::Skipped, 'No message could be built to score: '.$exception->getMessage());
        }

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post(static::SPAM_CHECKER, ['email' => $raw, 'options' => 'long']);
        } catch (HttpClientException|TransferException) {
            return $this->result('spam_score', $locale, CheckStatus::Skipped, 'Postmark\'s spam checker didn\'t answer.');
        }

        $score = $response->json('score');

        if (! $response->successful() || $response->json('success') !== true || ! is_numeric($score)) {
            return $this->result('spam_score', $locale, CheckStatus::Skipped, "Postmark's spam checker gave no score (HTTP {$response->status()}).");
        }

        $score = (float) $score;
        $rules = [];

        foreach ((array) $response->json('rules') as $rule) {
            if (is_array($rule) && is_numeric($rule['score'] ?? null) && (float) $rule['score'] !== 0.0) {
                $rules[] = ['score' => (float) $rule['score'], 'description' => mb_substr((string) ($rule['description'] ?? ''), 0, 200)];
            }
        }

        $message = "SpamAssassin scores it {$score}; ".static::SPAM_THRESHOLD.' or more is spam.';

        return $this->result('spam_score', $locale, $score < static::SPAM_THRESHOLD ? CheckStatus::Passed : CheckStatus::Failed, $message, [
            'score' => $score,
            'rules' => $rules,
        ]);
    }

    /**
     * The message as a mailbox would get it, built as a send builds it
     * (OutgoingMessage::toEmail()): a campaign's sender, else the app's,
     * with the headers a send carries, addressed to a placeholder recipient,
     * never a subscriber.
     *
     * @throws CampaignsException|InvalidArgumentException
     */
    protected function message(Revision $revision, RenderedEmail $email, Personalization $person): string
    {
        $campaign = $revision->revisable_type === (new Campaign)->getMorphClass()
            ? Campaign::query()->find($revision->revisable_id)
            : null;

        return (new OutgoingMessage(
            uuid: $revision->uuid,
            to: $person->email,
            fromEmail: $campaign === null ? (Sender::address() ?? throw CampaignsException::noSendingDomain()) : $campaign->from_email,
            fromName: $campaign === null ? Sender::name() : $campaign->from_name,
            replyTo: $campaign === null ? Sender::replyTo() : $campaign->reply_to,
            subject: $email->subject,
            html: $email->html,
            text: $email->text,
            unsubscribeUrl: $person->unsubscribeUrl,
            tag: $campaign === null ? TestSend::TAG : $campaign->tag,
            trackOpens: false,
            trackClicks: false,
        ))->toEmail()->toString();
    }

    /**
     * The http and https addresses the HTML's links point at.
     *
     * @return list<string>
     */
    protected function anchors(string $html): array
    {
        $hrefs = [];

        foreach (Html::parse($html)->getElementsByTagName('a') as $anchor) {
            /** @var DOMElement $anchor */
            $href = trim($anchor->getAttribute('href'));

            if (preg_match('#^https?://#i', $href) === 1) {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    protected function result(string $check, ?string $locale, CheckStatus $status, string $message, array $details = []): array
    {
        return [
            'check' => $check,
            'locale' => $locale,
            'status' => $status->value,
            'message' => $message,
            ...$details,
        ];
    }

    protected static function language(string $locale): string
    {
        return strtolower(str_replace('-', '_', $locale));
    }
}
