<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Generator;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Mail\CampaignEmail;
use Mozex\Campaigns\Support\Sender;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface as HttpClientTransportException;
use Throwable;

/**
 * Sends through Mailgun's HTTP API by way of the app's campaigns mailer
 * (campaigns.mail.mailer), which must use Mailgun's "api" scheme: that
 * transport turns the message's tag, metadata and o: options into the API's
 * own fields, where the MIME and SMTP ones would put them in the email as
 * headers. The log and array mailers are allowed too, for local runs and
 * tests, since they deliver nothing. Its logs and suppression lists are read
 * with campaigns.mailgun.api_key, since the mailer's sending key can only
 * send.
 */
class MailgunProvider implements CampaignProvider
{
    /**
     * Mailgun took nothing and may take it later: rate limited, or its
     * servers briefly failing. A 504 isn't here: a gateway that timed out
     * may have handed the message on.
     */
    protected const NOT_ACCEPTED = [429, 500, 502, 503];

    /**
     * Mailgun refuses the domain or the account (a wrong key, a disabled or
     * unknown domain), so every message from the domain would fail alike.
     */
    protected const DOMAIN_REFUSED = [401, 403, 404];

    /**
     * How far a webhook's signed timestamp may be from now, in seconds.
     */
    public const WEBHOOK_MAX_AGE = 86400;

    /**
     * Mailgun's event names the package acts on. Both failure webhooks
     * (temporary and permanent) arrive as "failed", their severity telling
     * them apart.
     */
    protected const EVENT_TYPES = [
        'accepted' => FeedbackType::Accepted,
        'delivered' => FeedbackType::Delivered,
        'failed' => FeedbackType::Failed,
        'opened' => FeedbackType::Opened,
        'clicked' => FeedbackType::Clicked,
        'unsubscribed' => FeedbackType::Unsubscribed,
        'complained' => FeedbackType::Complained,
    ];

    /**
     * Mailgun's own failure codes that say nothing about the address: 611,
     * its internal connection limit reached.
     */
    protected const NOT_THE_ADDRESS = [611];

    /**
     * Mailgun's code for a recipient whose mail servers it couldn't connect
     * to, which counts as a soft bounce.
     */
    protected const UNREACHABLE = 612;

    /**
     * The most pages of 100 one message's lookup reads.
     */
    protected const MESSAGE_PAGES = 5;

    /**
     * The Metrics API's counts a domain's day is read from, by the name
     * DomainMetrics gives each.
     */
    protected const METRICS = [
        'accepted_outgoing_count' => 'sent',
        'accepted_incoming_count' => 'forwarded',
        'hard_bounces_count' => 'hard_bounces',
        'complained_count' => 'complaints',
        'unsubscribed_count' => 'unsubscribes',
    ];

    /**
     * Mailgun's suppression lists, by their path.
     */
    protected const SUPPRESSION_LISTS = [
        'bounces' => SuppressionType::Bounce,
        'complaints' => SuppressionType::Complaint,
        'unsubscribes' => SuppressionType::Unsubscribe,
    ];

    public function send(OutgoingMessage $message): SendReceipt
    {
        $mailer = $this->mailer();

        $email = (new CampaignEmail($message))->withSymfonyMessage(function (Email $email) use ($message): void {
            // Both flags on every message, so the domain's own tracking
            // setting never decides for a campaign.
            $email->getHeaders()->addTextHeader('o:tracking-opens', $message->trackOpens ? 'yes' : 'no');
            $email->getHeaders()->addTextHeader('o:tracking-clicks', $message->trackClicks ? 'yes' : 'no');
        });

        try {
            $sent = $mailer->send($email);
        } catch (HttpTransportException $exception) {
            throw $this->failure($exception);
        } catch (TransportExceptionInterface $exception) {
            // Not chained, and its message not kept: a transport's error can
            // quote the recipient's address.
            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        } catch (Throwable $exception) {
            // Anything else, such as an app's MessageSent listener failing
            // after Mailgun took the message, or an answer the transport
            // couldn't read: reported, since it is a bug somewhere, and
            // unclear, since the message may have gone.
            report($exception);

            throw new SendFailed(SendFailure::Unclear, 'The mailer failed while sending ('.class_basename($exception).'), so the message may have gone.');
        }

        $id = trim((string) $sent?->getMessageId(), "<> \t");

        if ($id === '') {
            // A MessageSending listener can stop a message, and a faked
            // mailer records it instead: either way there is no receipt.
            throw new SendFailed(SendFailure::Unclear, 'The mailer gave no message id, so whether the message went is unknown.');
        }

        // Mailgun's answer wraps the id in angle brackets and its events
        // don't, so it is stored bare.
        return new SendReceipt($id);
    }

    /**
     * The HMAC of the signature's timestamp and token, keyed with the
     * account's webhook signing key, against its signature (or, for a
     * subaccount's domain, the parent account's). The signature covers only
     * the timestamp and token, so the token is what stops a replay
     * (RecordFeedbackAction), and a timestamp more than WEBHOOK_MAX_AGE
     * seconds from now is refused: Mailgun retries for about 8 hours and
     * may not sign a retry again.
     *
     * @throws CampaignsException when no signing key is configured, so the
     *                            request answers 500 and Mailgun retries
     *                            while the config is fixed
     */
    public function verifyWebhook(Request $request): bool
    {
        $key = config('campaigns.mailgun.webhook_signing_key');

        if (! is_string($key) || $key === '') {
            throw CampaignsException::missingWebhookSigningKey();
        }

        $signature = $request->json('signature');

        if (! is_array($signature)) {
            return false;
        }

        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;

        if (is_int($timestamp)) {
            $timestamp = (string) $timestamp;
        }

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || strlen($timestamp) > 12
            || ! is_string($token) || $token === '' || strlen($token) > 255) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > static::WEBHOOK_MAX_AGE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.$token, $key);

        foreach (['signature', 'parent-signature'] as $field) {
            if (is_string($signature[$field] ?? null) && hash_equals($expected, strtolower($signature[$field]))) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $event = $request->json('event-data');
        $token = $request->json('signature.token');

        if (! is_array($event)) {
            return [];
        }

        $feedback = $this->feedback($event, is_string($token) ? $token : null);

        return $feedback === null ? [] : [$feedback];
    }

    /**
     * Read through the Logs API, filtered by the uuid in the message's
     * user-variables. Only events carrying exactly that uuid are returned,
     * so a filter Mailgun read differently can't pass another email's events
     * off as this one's; a message has a handful, so a few pages are read at
     * most, and a lookup that leaves pages unread tells nothing (null).
     */
    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
    {
        $uuid = strtolower($messageUuid);
        $found = [];

        $events = $this->logs(
            [['attribute' => 'user_variables', 'comparator' => 'contains', 'values' => [['label' => $uuid, 'value' => $uuid]]]],
            $since,
            now(),
            static::MESSAGE_PAGES,
        );

        foreach ($events as $feedback) {
            if (strtolower((string) $feedback->messageUuid) === $uuid) {
                $found[] = $feedback;
            }
        }

        // Pages left unread may hold this email's events.
        return $events->getReturn() ? null : $found;
    }

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        $domain = $this->mailgunDomain($domain);

        // Without accepted, which every other event of a message proves and
        // which doubles what a busy domain's replay must read; a message
        // whose send ended unclear is looked up with it (eventsForMessage()).
        return $this->logs(
            [['attribute' => 'domain', 'comparator' => '=', 'values' => [['label' => $domain, 'value' => $domain]]]],
            $from,
            $to,
            types: array_values(array_diff(array_keys(static::EVENT_TYPES), ['accepted'])),
        );
    }

    public function logDays(): int
    {
        return max(1, (int) config('campaigns.mailgun.log_days', 5));
    }

    /**
     * Mailgun's bounce, complaint and unsubscribe lists for the domain, each
     * read in pages of 1,000 to its end (an empty page).
     */
    public function suppressions(string $domain): iterable
    {
        $domain = $this->mailgunDomain($domain);

        foreach (static::SUPPRESSION_LISTS as $list => $type) {
            $url = $this->apiBase().'/v3/'.rawurlencode($domain).'/'.$list.'?limit=1000';
            $seen = [];

            while (! isset($seen[$url])) {
                $seen[$url] = true;
                $json = $this->read('GET', $url);
                $items = is_array($json['items'] ?? null) ? $json['items'] : [];

                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    $suppression = is_array($item) ? $this->suppression($item, $type) : null;

                    if ($suppression !== null) {
                        yield $suppression;
                    }
                }

                $url = $this->nextPage(data_get($json, 'paging.next'));
            }
        }
    }

    /**
     * The domain is checked first: Mailgun answers 404 for a domain the key
     * or region can't see as it does for an address no list holds, which
     * would read as "not held".
     */
    public function suppressionsFor(string $domain, string $email): array
    {
        $domain = $this->mailgunDomain($domain);

        if ($this->read('GET', $this->apiBase().'/v3/domains/'.rawurlencode($domain), notFound: true) === null) {
            throw CampaignsException::providerUnreadable("Mailgun has no domain {$domain} for this key in this region (campaigns.mailgun.endpoint).");
        }

        $found = [];

        foreach (static::SUPPRESSION_LISTS as $list => $type) {
            $json = $this->read('GET', $this->apiBase().'/v3/'.rawurlencode($domain).'/'.$list.'/'.rawurlencode($email), notFound: true);
            $suppression = $json === null ? null : $this->suppression($json, $type);

            if ($suppression !== null) {
                $found[] = $suppression;
            }
        }

        return $found;
    }

    /**
     * Read through the Metrics API a day at a time. Its start and end are
     * explicit times inside the period, never a duration or the first
     * instant of the next day: Mailgun rounds both down to their day's start
     * and answers every day from the first to the last whole, so an end at
     * midnight would add the next day. The days it answers outside the
     * period are dropped, and a day it leaves out counts 0. Its delivery and
     * failure counts include mail the domain's routes forward, so they're
     * left out; its outgoing count is the domain's own sends.
     */
    public function domainMetrics(string $domain, CarbonInterface $from, CarbonInterface $to): DomainMetrics
    {
        $domain = $this->mailgunDomain($domain);
        $first = Carbon::instance($from)->utc()->startOfDay();
        $last = Carbon::instance($to)->utc()->startOfDay();

        $json = $this->read('POST', $this->apiBase().'/v1/analytics/metrics', [
            'start' => Carbon::instance($from)->utc()->toRfc2822String(),
            'end' => Carbon::instance($to)->utc()->toRfc2822String(),
            'resolution' => 'day',
            'dimensions' => ['time'],
            'metrics' => array_keys(static::METRICS),
            'filter' => ['AND' => [['attribute' => 'domain', 'comparator' => '=', 'values' => [['label' => $domain, 'value' => $domain]]]]],
            'include_aggregates' => false,
        ]);

        $days = [];

        for ($day = $first->copy(); $day->lessThanOrEqualTo($last); $day->addDay()) {
            $days[$day->toDateString()] = array_fill_keys(DomainMetrics::COUNTS, 0);
        }

        foreach (is_array($json['items'] ?? null) ? $json['items'] : [] as $item) {
            $date = $this->metricsDay(is_array($item) ? $item : []);

            if ($date === null || ! isset($days[$date])) {
                continue;
            }

            foreach (static::METRICS as $metric => $count) {
                $value = data_get($item, 'metrics.'.$metric);
                $days[$date][$count] = is_numeric($value) ? max(0, (int) $value) : 0;
            }
        }

        /** @var array<string, array{sent: int, forwarded: int, hard_bounces: int, complaints: int, unsubscribes: int}> $days */
        return new DomainMetrics($days);
    }

    public function tracks(): bool
    {
        return true;
    }

    /**
     * The Logs API's events matching a filter in a period, oldest first, a
     * page of 100 at a time, following its page token to the end or the
     * page limit. The period starts no earlier than the logs reach. Events
     * the package doesn't act on are left out. The generator returns true
     * when it stopped at the page limit with pages left.
     *
     * @param  list<array<string, mixed>>  $filter
     * @param  ?list<string>  $types  the event types asked for; null for every one the package reads
     * @return Generator<int, Feedback, mixed, bool>
     */
    protected function logs(array $filter, CarbonInterface $from, CarbonInterface $to, int $pages = PHP_INT_MAX, ?array $types = null): Generator
    {
        // A minute inside the retention, so a start at its edge is never
        // refused as too old.
        $oldest = now()->subDays($this->logDays())->addMinute();
        $from = $from->lessThan($oldest) ? $oldest : $from;

        if ($from->greaterThanOrEqualTo($to)) {
            return false;
        }

        $token = null;
        $seen = [];

        for ($page = 0; $page < $pages; $page++) {
            $json = $this->read('POST', $this->apiBase().'/v1/analytics/logs', [
                'start' => Carbon::instance($from)->utc()->toRfc2822String(),
                'end' => Carbon::instance($to)->utc()->toRfc2822String(),
                'events' => $types ?? array_keys(static::EVENT_TYPES),
                'filter' => ['AND' => $filter],
                'pagination' => array_filter([
                    'sort' => 'timestamp:asc',
                    'limit' => 100,
                    'token' => $token,
                ]),
            ]);

            $items = is_array($json['items'] ?? null) ? $json['items'] : [];

            foreach ($items as $item) {
                $feedback = is_array($item) ? $this->feedback($item, null) : null;

                if ($feedback !== null) {
                    yield $feedback;
                }
            }

            $token = data_get($json, 'pagination.next');

            if ($items === [] || ! is_string($token) || $token === '' || isset($seen[$token])) {
                return false;
            }

            $seen[$token] = true;
        }

        return true;
    }

    /**
     * A metrics item's UTC date, from its time dimension (the day's start as
     * an RFC 2822 time).
     *
     * @param  array<mixed>  $item
     */
    protected function metricsDay(array $item): ?string
    {
        foreach (is_array($item['dimensions'] ?? null) ? $item['dimensions'] : [] as $dimension) {
            if (is_array($dimension) && ($dimension['dimension'] ?? null) === 'time' && is_string($dimension['value'] ?? null)) {
                try {
                    return Carbon::parse($dimension['value'])->utc()->toDateString();
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * The domain Mailgun knows the marketing domain's mail by: the campaigns
     * mailer's own Mailgun domain when it names one (a From address on
     * example.com can send through mg.example.com), since Mailgun keeps logs
     * and suppressions by the domain it sent through. Any other domain is
     * asked for as given.
     */
    protected function mailgunDomain(string $domain): string
    {
        try {
            $marketing = Sender::domain();
        } catch (CampaignsException) {
            return $domain;
        }

        $sendsThrough = $this->mailerSetting('domain');

        return strtolower($domain) === $marketing && is_string($sendsThrough) && trim($sendsThrough) !== ''
            ? strtolower(trim($sendsThrough))
            : $domain;
    }

    /**
     * One request to Mailgun's API with the reading key, its JSON answer, or
     * null for a 404 where one means "not there". Nothing that fails here is
     * chained or quotes the request: its address can hold a recipient's, and
     * Mailgun's answer can name one.
     *
     * @param  array<string, mixed>  $body
     * @return ?array<mixed>
     *
     * @throws CampaignsException
     */
    protected function read(string $method, string $url, array $body = [], bool $notFound = false): ?array
    {
        $key = config('campaigns.mailgun.api_key');

        if (! is_string($key) || $key === '') {
            throw CampaignsException::missingMailgunApiKey();
        }

        $request = Http::withBasicAuth('api', $key)->acceptJson()->connectTimeout(10)->timeout(30);

        try {
            $response = $method === 'POST' ? $request->post($url, $body) : $request->get($url);
        } catch (Throwable $exception) {
            throw CampaignsException::providerUnreadable('Mailgun couldn\'t be reached ('.class_basename($exception).').');
        }

        if ($notFound && $response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            $fix = match ($response->status()) {
                401, 403 => ' campaigns.mailgun.api_key must be an account API key that may read logs, metrics and suppressions (a domain sending key can only send), in the region campaigns.mailgun.endpoint names.',
                404 => ' Check that the sending domain exists in the region campaigns.mailgun.endpoint names.',
                default => '',
            };

            throw CampaignsException::providerUnreadable("Mailgun answered {$response->status()} when its logs, suppressions or metrics were read.{$fix}");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw CampaignsException::providerUnreadable('Mailgun\'s answer couldn\'t be read.');
        }

        return $json;
    }

    /**
     * The API's address for the region: campaigns.mailgun.endpoint, else the
     * campaigns mailer's endpoint (or services.mailgun's, which Laravel uses
     * for a mailer with no secret of its own), else Mailgun's US host.
     */
    protected function apiBase(): string
    {
        $endpoint = config('campaigns.mailgun.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '') {
            $endpoint = $this->mailerSetting('endpoint');
        }

        $host = is_string($endpoint) ? trim((string) preg_replace('#^https?://#i', '', trim($endpoint)), '/') : '';

        return 'https://'.($host === '' || $host === 'default' ? 'api.mailgun.net' : $host);
    }

    /**
     * One of the campaigns mailer's Mailgun settings, or services.mailgun's,
     * which Laravel uses for a mailer with no secret of its own.
     */
    protected function mailerSetting(string $key): mixed
    {
        $mailer = config('mail.mailers.'.trim(Sender::mailer()));

        return is_array($mailer) && isset($mailer['secret']) ? ($mailer[$key] ?? null) : config('services.mailgun.'.$key);
    }

    /**
     * A suppression list's next page, which Mailgun gives as a whole address:
     * followed only on the same host, so the key never goes anywhere else.
     */
    protected function nextPage(mixed $url): string
    {
        $base = $this->apiBase().'/';

        if (! is_string($url) || ! str_starts_with($url, $base)) {
            throw CampaignsException::providerUnreadable('Mailgun\'s suppression list pointed its next page away from its API.');
        }

        return $url;
    }

    /**
     * One record of a suppression list. Its error text is scrubbed of
     * addresses, since a mail server's refusal often quotes one.
     *
     * @param  array<mixed>  $item
     */
    protected function suppression(array $item, SuppressionType $type): ?Suppression
    {
        $email = $this->string($item['address'] ?? null);
        $tags = $item['tags'] ?? null;

        // An unsubscribe from some tags only ('*' is every tag) stops only
        // mail carrying them, and a campaign's tag is its own key.
        if ($email === null || ($type === SuppressionType::Unsubscribe && is_array($tags) && $tags !== [] && ! in_array('*', $tags, true))) {
            return null;
        }

        try {
            $since = is_string($item['created_at'] ?? null) ? Carbon::parse($item['created_at']) : null;
        } catch (Throwable) {
            $since = null;
        }

        $reason = trim(implode(' ', array_filter([
            is_scalar($item['code'] ?? null) ? (string) $item['code'] : null,
            is_string($item['error'] ?? null) ? $item['error'] : null,
        ])));

        return new Suppression($email, $type, $since, $reason === '' ? null : SendFailed::scrub($reason, 255));
    }

    /**
     * The campaigns mailer, checked before anything is sent: a mailer that
     * isn't named or can't be built, one that would put Mailgun's options in
     * the email, or one that delivers nothing in production refuses for the
     * whole domain until its config is fixed.
     *
     * @throws SendFailed
     */
    protected function mailer(): MailerContract
    {
        $name = trim(Sender::mailer());

        // A blank name would resolve the app's default mailer, which is
        // usually the transactional domain's.
        if ($name === '') {
            throw new SendFailed(SendFailure::DomainRefused, 'No campaigns mailer is named (campaigns.mail.mailer, CAMPAIGNS_MAILER).');
        }

        try {
            $mailer = Mail::mailer($name);
        } catch (Throwable $exception) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" can't be used: ".SendFailed::scrub($exception->getMessage()));
        }

        // A faked mailer (Mail::fake()) has no transport to check.
        if (! $mailer instanceof Mailer) {
            return $mailer;
        }

        $transport = $mailer->getSymfonyTransport();
        $delivers = $transport instanceof MailgunApiTransport;

        if (! $delivers && ! $transport instanceof LogTransport && ! $transport instanceof ArrayTransport) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" must send through Mailgun's API: give it the mailgun transport with 'scheme' => 'api' (or the log or array mailer locally).");
        }

        // The log mailer would mark a whole campaign sent with nothing
        // delivered, and write every message, links' tokens and all, to the
        // app's log.
        if (! $delivers && app()->isProduction()) {
            throw new SendFailed(SendFailure::DomainRefused, "The campaigns mailer \"{$name}\" delivers nothing, which production never sends through: give it the mailgun transport with 'scheme' => 'api'.");
        }

        return $mailer;
    }

    protected function failure(HttpTransportException $exception): SendFailed
    {
        if ($exception->getPrevious() instanceof HttpClientTransportException) {
            return new SendFailed(SendFailure::Unclear, 'The connection to Mailgun failed before it answered, so it may have the message.');
        }

        try {
            $status = $exception->getResponse()->getStatusCode();
        } catch (Throwable) {
            return new SendFailed(SendFailure::Unclear, 'Mailgun\'s answer couldn\'t be read, so it may have the message.');
        }

        $failure = match (true) {
            in_array($status, static::NOT_ACCEPTED, true) => SendFailure::NotAccepted,
            in_array($status, static::DOMAIN_REFUSED, true) => SendFailure::DomainRefused,
            default => SendFailure::Unclear,
        };

        $reason = $this->reason($exception);

        return new SendFailed($failure, "Mailgun answered {$status}".($reason === '' ? '.' : ": {$reason}"), $status);
    }

    /**
     * Mailgun's own words for a refusal, without any address in them.
     */
    protected function reason(HttpTransportException $exception): string
    {
        try {
            $body = $exception->getResponse()->getContent(false);
        } catch (Throwable) {
            return '';
        }

        $json = json_decode($body, true);

        $text = match (true) {
            is_array($json) && is_string($json['message'] ?? null) => $json['message'],
            is_string($json) => $json,
            default => strip_tags($body),
        };

        return SendFailed::scrub($text);
    }

    /**
     * One Mailgun event in the package's terms, or null for an event the
     * package doesn't act on or one too broken to place. The details kept
     * are those an effect or a report reads; the recipient's address, IP,
     * the message's headers and the envelope stay out, and the delivery
     * status's text is scrubbed of addresses, since a mail server's refusal
     * often quotes one. user-variables is an object when the message carried
     * any and an empty array when it didn't.
     *
     * @param  array<mixed>  $event
     */
    protected function feedback(array $event, ?string $replayKey): ?Feedback
    {
        $name = $event['event'] ?? null;
        $type = is_string($name) ? (static::EVENT_TYPES[$name] ?? null) : null;
        $id = $event['id'] ?? null;
        $occurredAt = $this->time($event['timestamp'] ?? null, $event['@timestamp'] ?? null);

        if ($type === null || ! is_string($id) || $id === '' || strlen($id) > 255 || $occurredAt === null) {
            return null;
        }

        $deliveredAt = $this->time($event['delivered-at'] ?? null, $event['delivered-at'] ?? null);

        $variables = $event['user-variables'] ?? [];

        if (is_string($variables)) {
            $variables = json_decode($variables, true);
        }

        $variables = is_array($variables) ? $variables : [];
        $status = is_array($event['delivery-status'] ?? null) ? $event['delivery-status'] : [];
        $client = is_array($event['client-info'] ?? null) ? $event['client-info'] : [];
        $flags = is_array($event['flags'] ?? null) ? $event['flags'] : [];

        // Without the angle brackets, as the package stores the id it got
        // when Mailgun took the message.
        $messageId = $this->string(trim((string) $this->string(data_get($event, 'message.headers.message-id')), '<>'));
        $severity = $this->string($event['severity'] ?? null);
        $reason = $this->string($event['reason'] ?? null);
        $code = is_numeric($status['code'] ?? null) ? (int) $status['code'] : null;
        $bounceType = $this->string($status['bounce-type'] ?? null);

        return new Feedback(
            providerEventId: $id,
            type: $type,
            occurredAt: $occurredAt,
            recipient: $this->string($event['recipient'] ?? null) ?? '',
            messageUuid: $this->string($variables[OutgoingMessage::MESSAGE_KEY] ?? null),
            testSendUuid: $this->string($variables[OutgoingMessage::TEST_KEY] ?? null),
            providerMessageId: $messageId,
            severity: $severity,
            reason: $reason,
            failure: $type === FeedbackType::Failed ? $this->deliveryFailure($severity, $reason, $code, $bounceType) : null,
            code: $code,
            bot: $this->string($client['bot'] ?? null),
            url: $this->string($event['url'] ?? null, 4000),
            replayKey: $replayKey,
            payload: array_filter([
                'delivery-status' => array_filter([
                    'attempt-no' => is_int($status['attempt-no'] ?? null) ? $status['attempt-no'] : null,
                    'bounce-type' => $bounceType,
                    'enhanced-code' => $this->string($status['enhanced-code'] ?? null),
                    'message' => is_string($status['message'] ?? null) ? SendFailed::scrub($status['message'], 255) : null,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
                'client-info' => array_filter([
                    'client-name' => $this->string($client['client-name'] ?? null),
                    'client-os' => $this->string($client['client-os'] ?? null),
                    'client-type' => $this->string($client['client-type'] ?? null),
                    'device-type' => $this->string($client['device-type'] ?? null),
                    'user-agent' => $this->string($client['user-agent'] ?? null),
                ], fn (?string $value): bool => $value !== null),
                // A number, as webhooks give it; the logs give a time.
                'delivered-at' => $deliveredAt === null ? null : (float) $deliveredAt->format('U.u'),
                'recipient-provider' => $this->string($event['recipient-provider'] ?? null),
                'is-test-mode' => is_bool($flags['is-test-mode'] ?? null) ? $flags['is-test-mode'] : null,
                'is-routed' => is_bool($flags['is-routed'] ?? null) ? $flags['is-routed'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * What a failed event means. Mailgun's suppressions come first: it sent
     * nothing, since it holds the address on one of its lists. A block of the
     * sender and code 611 say nothing about the address, still being tried or
     * not. Anything else that isn't permanent is still being tried (a
     * temporary failure, and Mailgun's internal codes, whose severity it
     * doesn't document), so it counts for nothing yet: Mailgun reports a
     * permanent "old" once it gives up. Of the permanent ones, 612 and a soft
     * bounce type count one soft bounce, a hard bounce type is a hard bounce,
     * and only then does the reason decide: "bounce" hard, "old" (given up
     * after retries) soft. Mailgun suppresses only hard ones, so a bounce
     * whose type is soft never blocks the address for good.
     */
    protected function deliveryFailure(?string $severity, ?string $reason, ?int $code, ?string $bounceType): DeliveryFailure
    {
        $permanent = $severity === 'permanent';

        return match (true) {
            $reason === 'suppress-bounce' => DeliveryFailure::SuppressedBounce,
            $reason === 'suppress-complaint' => DeliveryFailure::SuppressedComplaint,
            $reason === 'suppress-unsubscribe' => DeliveryFailure::SuppressedUnsubscribe,
            $reason === 'espblock', in_array($code, static::NOT_THE_ADDRESS, true) => $permanent ? DeliveryFailure::SenderBlocked : DeliveryFailure::SenderDeferred,
            ! $permanent => DeliveryFailure::Deferred,
            $code === static::UNREACHABLE, $bounceType === 'soft' => DeliveryFailure::SoftBounce,
            $bounceType === 'hard' => DeliveryFailure::HardBounce,
            $reason === 'bounce' => DeliveryFailure::HardBounce,
            $reason === 'old' => DeliveryFailure::SoftBounce,
            default => DeliveryFailure::Other,
        };
    }

    /**
     * A non-empty string from an event's field, cut to fit, or null.
     */
    protected function string(mixed $value, int $max = 255): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strimwidth(trim($value), 0, $max);
    }

    /**
     * An event's time: a webhook's event-data has a Unix "timestamp", a
     * Logs API item an ISO "@timestamp".
     */
    protected function time(mixed $timestamp, mixed $iso): ?Carbon
    {
        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampUTC((float) $timestamp);
        }

        if (! is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
