<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Sending\MailgunProvider;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\Suppression;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A campaigns mailer on Mailgun's API whose HTTP requests go to $respond,
 * which gets what Symfony's real transport would post.
 *
 * @param  Closure(string, string, array<string, mixed>): MockResponse  $respond
 */
function mailgunMailer(Closure $respond): void
{
    config()->set('mail.mailers.campaigns', [
        'transport' => 'mailgun',
        'scheme' => 'api',
        'secret' => 'key-test',
        'domain' => 'news.example.com',
        'endpoint' => 'api.eu.mailgun.net',
    ]);

    Mail::mailer('campaigns')->setSymfonyTransport(
        new MailgunApiTransport('key-test', 'news.example.com', 'eu', new MockHttpClient($respond)),
    );
}

/**
 * The multipart form fields a request posted, each name with its values.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, list<string>>
 */
function postedFields(array $options): array
{
    $body = $options['body'];

    if ($body instanceof Closure) {
        $read = '';

        while (($chunk = $body(8192)) !== '') {
            $read .= $chunk;
        }

        $body = $read;
    }

    /** @var list<string> $headers */
    $headers = $options['headers'];
    preg_match('/boundary=([^\s;]+)/', implode("\n", $headers), $boundary);

    $fields = [];

    foreach (explode('--'.$boundary[1], (string) $body) as $part) {
        if (preg_match('/name="([^"]+)"/', $part, $name) !== 1) {
            continue;
        }

        $fields[$name[1]][] = substr(explode("\r\n\r\n", $part, 2)[1], 0, -2);
    }

    return $fields;
}

function outgoing(bool $isTest = false, bool $trackOpens = true, bool $trackClicks = true): OutgoingMessage
{
    return new OutgoingMessage(
        uuid: '0a1b2c3d-0000-4000-8000-000000000001',
        to: 'reader@example.org',
        fromEmail: 'news@news.example.com',
        fromName: 'Example News',
        replyTo: 'help@example.com',
        subject: 'Spring sale',
        html: '<p>Hello</p>',
        text: 'Hello',
        unsubscribeUrl: 'https://app.example.com/campaigns/u/abc',
        tag: 'spring-sale',
        trackOpens: $trackOpens,
        trackClicks: $trackClicks,
        isTest: $isTest,
    );
}

function sendFailure(Closure $send): SendFailed
{
    try {
        $send();
    } catch (SendFailed $failed) {
        return $failed;
    }

    throw new RuntimeException('Nothing was refused.');
}

it('posts the message as Mailgun\'s API fields, with every tracking flag set, and keeps the bare id', function (): void {
    $requests = [];

    mailgunMailer(function (string $method, string $url, array $options) use (&$requests): MockResponse {
        $requests[] = [$method, $url, postedFields($options), $options];

        return new MockResponse('{"id":"<20261029.abc@news.example.com>","message":"Queued. Thank you."}', ['http_code' => 200]);
    });

    $receipt = app(MailgunProvider::class)->send(outgoing(trackOpens: false));
    $test = app(MailgunProvider::class)->send(outgoing(isTest: true));

    [$method, $url, $fields, $options] = $requests[0];

    expect($receipt->providerMessageId)->toBe('20261029.abc@news.example.com')
        ->and($test->providerMessageId)->toBe('20261029.abc@news.example.com')
        ->and($method)->toBe('POST')
        ->and($url)->toBe('https://api.eu.mailgun.net/v3/news.example.com/messages')
        ->and(implode("\n", $options['headers']))->toContain('Authorization: Basic '.base64_encode('api:key-test'))
        ->and($fields['from'])->toBe(['"Example News" <news@news.example.com>'])
        ->and($fields['to'])->toBe(['reader@example.org'])
        ->and($fields['subject'])->toBe(['Spring sale'])
        ->and($fields['html'])->toBe(['<p>Hello</p>'])
        ->and($fields['text'])->toBe(['Hello'])
        ->and($fields['h:Reply-To'])->toBe(['help@example.com'])
        ->and($fields['h:List-Unsubscribe'])->toBe(['<https://app.example.com/campaigns/u/abc>'])
        ->and($fields['h:List-Unsubscribe-Post'])->toBe(['List-Unsubscribe=One-Click'])
        ->and($fields['o:tag'])->toBe(['spring-sale'])
        ->and($fields['v:campaign-message'])->toBe(['0a1b2c3d-0000-4000-8000-000000000001'])
        ->and($fields)->not->toHaveKey('v:campaign-test')
        ->and($fields['o:tracking-opens'])->toBe(['no'])
        ->and($fields['o:tracking-clicks'])->toBe(['yes'])
        ->and(array_filter(array_keys($fields), fn (string $name): bool => str_starts_with($name, 'h:o:') || str_starts_with($name, 'h:X-Mailgun')))->toBe([])
        ->and($requests[1][2]['v:campaign-test'])->toBe(['0a1b2c3d-0000-4000-8000-000000000001'])
        ->and($requests[1][2])->not->toHaveKey('v:campaign-message')
        ->and($requests[1][2]['o:tracking-opens'])->toBe(['yes']);
});

it('tells what Mailgun\'s answer means for the message, without its addresses', function (int $status, string $body, SendFailure $failure): void {
    mailgunMailer(fn (): MockResponse => new MockResponse($body, ['http_code' => $status]));

    $failed = sendFailure(fn () => app(MailgunProvider::class)->send(outgoing()));

    expect($failed->failure)->toBe($failure)
        ->and($failed->status)->toBe($status)
        ->and($failed->getMessage())->toStartWith("Mailgun answered {$status}")
        ->and($failed->getMessage())->not->toContain('reader@example.org')
        ->and($failed->getPrevious())->toBeNull();
})->with([
    'rate limited' => [429, '{"message":"Domain news.example.com is not allowed to send: account-requests-per-sec limit exceeded, try again after 120 seconds"}', SendFailure::NotAccepted],
    'a server error' => [500, '{"message":"Internal Server Error"}', SendFailure::NotAccepted],
    'a bad gateway' => [502, '<html><body>Bad Gateway</body></html>', SendFailure::NotAccepted],
    'unavailable' => [503, '{"message":"Service Unavailable"}', SendFailure::NotAccepted],
    'a wrong key' => [401, 'Forbidden', SendFailure::DomainRefused],
    'a refused domain' => [403, '{"message":"Domain news.example.com is not allowed to send: domain is disabled"}', SendFailure::DomainRefused],
    'an unknown domain' => [404, '{"message":"Domain not found"}', SendFailure::DomainRefused],
    'a refused message' => [400, '{"message":"\'to\' parameter is not a valid address: reader@example.org"}', SendFailure::Unclear],
    'too large' => [413, '{"message":"Request entity too large"}', SendFailure::Unclear],
    'a gateway timeout, which may have gone through' => [504, '<html><body>Gateway Timeout</body></html>', SendFailure::Unclear],
    'an answer that isn\'t JSON' => [200, 'not json for reader@example.org', SendFailure::Unclear],
]);

it('treats a connection lost before Mailgun answered as unclear, never as not taken', function (): void {
    mailgunMailer(fn (): MockResponse => new MockResponse('', ['error' => 'Idle timeout reached for "https://api.eu.mailgun.net".']));

    $failed = sendFailure(fn () => app(MailgunProvider::class)->send(outgoing()));

    expect($failed->failure)->toBe(SendFailure::Unclear)
        ->and($failed->status)->toBeNull()
        ->and($failed->getPrevious())->toBeNull();
});

// Every transport here points nowhere real (a closed local port, or one that
// delivers nothing), so a guard that stopped refusing could never reach a
// real server.
it('refuses for the domain, before anything goes out, a mailer that would put Mailgun\'s options in the email', function (Closure $setUp, string $says): void {
    $setUp();

    $failed = sendFailure(fn () => app(MailgunProvider::class)->send(outgoing()));

    expect($failed->failure)->toBe(SendFailure::DomainRefused)
        ->and($failed->getMessage())->toContain($says);
})->with([
    'Mailgun\'s MIME scheme' => [fn () => config()->set('mail.mailers.campaigns', ['transport' => 'mailgun', 'secret' => 'key-test', 'domain' => 'news.example.com', 'endpoint' => '127.0.0.1:9']), "'scheme' => 'api'"],
    'an SMTP transport' => [function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'array']);
        Mail::mailer('campaigns')->setSymfonyTransport(new EsmtpTransport('127.0.0.1', 9));
    }, "'scheme' => 'api'"],
    'another transport' => [function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'array']);
        Mail::mailer('campaigns')->setSymfonyTransport(new NullTransport);
    }, "'scheme' => 'api'"],
    'none defined' => [fn () => config()->set('mail.mailers.campaigns', []), "can't be used"],
    'none named' => [fn () => config()->set('campaigns.mail.mailer', ''), 'No campaigns mailer is named'],
    'the log mailer in production' => [function (): void {
        config()->set('mail.mailers.campaigns', ['transport' => 'log']);
        app()->detectEnvironment(fn (): string => 'production');
    }, 'delivers nothing'],
]);

it('sends through the log and array mailers locally, as the campaign says whatever the app\'s mail config adds', function (): void {
    config()->set('mail.reply_to', ['address' => 'everything@example.com', 'name' => null]);
    config()->set('mail.return_path', 'bounces@example.com');
    config()->set('mail.mailers.campaigns', ['transport' => 'array']);

    $receipt = app(MailgunProvider::class)->send(outgoing());
    app(MailgunProvider::class)->send(new OutgoingMessage(
        uuid: 'x', to: 'reader@example.org', fromEmail: 'news@news.example.com', fromName: null, replyTo: null, subject: '',
        html: '<p>Hi</p>', text: 'Hi', unsubscribeUrl: 'https://app.example.com/campaigns/u/abc', tag: 't', trackOpens: true, trackClicks: true,
    ));

    /** @var list<Email> $sent */
    $sent = Mail::mailer('campaigns')->getSymfonyTransport()->messages()->map(fn (SentMessage $message): Email => $message->getOriginalMessage())->all();

    config()->set('mail.mailers.local', ['transport' => 'log']);
    config()->set('campaigns.mail.mailer', 'local');
    $logged = app(MailgunProvider::class)->send(outgoing());

    expect($receipt->providerMessageId)->not->toBe('')
        ->and($receipt->providerMessageId)->not->toContain('<')
        ->and($logged->providerMessageId)->not->toBe('')
        ->and($sent[0]->getHeaders()->get('List-Unsubscribe')?->getBodyAsString())->toBe('<https://app.example.com/campaigns/u/abc>')
        ->and($sent[0]->getHeaders()->get('o:tracking-opens')?->getBodyAsString())->toBe('yes')
        ->and($sent[0]->getTextBody())->toBe('Hello')
        ->and(array_map(fn (Address $address): string => $address->getAddress(), $sent[0]->getReplyTo()))->toBe(['help@example.com'])
        ->and($sent[0]->getHeaders()->has('Return-Path'))->toBeFalse()
        ->and($sent[1]->getReplyTo())->toBe([])
        ->and($sent[1]->getSubject())->toBe('');
});

it('reports a send it can\'t confirm as unclear: a listener that stopped it, or one that failed after it', function (): void {
    Exceptions::fake();
    config()->set('mail.mailers.campaigns', ['transport' => 'array']);

    Event::listen(MessageSending::class, fn (): bool => false);
    $stopped = sendFailure(fn () => app(MailgunProvider::class)->send(outgoing()));

    Event::forget(MessageSending::class);
    Event::listen(MessageSent::class, fn () => throw new RuntimeException('A listener of the app failed.'));
    $afterwards = sendFailure(fn () => app(MailgunProvider::class)->send(outgoing()));

    expect($stopped->failure)->toBe(SendFailure::Unclear)
        ->and($afterwards->failure)->toBe(SendFailure::Unclear)
        ->and($afterwards->getMessage())->toContain('RuntimeException')
        ->and($afterwards->getPrevious())->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

it('tells what a failure means: still being tried, a hard or soft bounce, a suppression, or nothing against the address', function (array $changes, ?DeliveryFailure $failure): void {
    config()->set('campaigns.mailgun.webhook_signing_key', 'webhook-signing-key');
    $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['event-data' => mailgunEvent('permanent-failure', $changes)]));

    expect(app(MailgunProvider::class)->parseWebhook($request)[0]->failure)->toBe($failure);
})->with([
    // The documented sample: a permanent bounce, its type hard.
    'a hard bounce' => [[], DeliveryFailure::HardBounce],
    'a bounce with no type' => [['delivery-status' => ['bounce-type' => null]], DeliveryFailure::HardBounce],
    'a bounce whose type is soft' => [['delivery-status' => ['bounce-type' => 'soft']], DeliveryFailure::SoftBounce],
    'a bounce type of hard' => [['reason' => 'generic'], DeliveryFailure::HardBounce],
    'given up on after retries' => [['reason' => 'old', 'delivery-status' => ['bounce-type' => null]], DeliveryFailure::SoftBounce],
    'a bounce type of soft' => [['reason' => 'generic', 'delivery-status' => ['bounce-type' => 'soft']], DeliveryFailure::SoftBounce],
    'the servers unreachable, given up on' => [['reason' => 'generic', 'delivery-status' => ['code' => 612, 'bounce-type' => null]], DeliveryFailure::SoftBounce],
    'the servers unreachable, still being tried' => [['severity' => 'internal', 'delivery-status' => ['code' => 612]], DeliveryFailure::Deferred],
    'Mailgun\'s own connection limit' => [['reason' => 'generic', 'delivery-status' => ['code' => 611]], DeliveryFailure::SenderBlocked],
    'Mailgun\'s own connection limit, still being tried' => [['severity' => 'internal', 'delivery-status' => ['code' => 611]], DeliveryFailure::SenderDeferred],
    'still being tried' => [['severity' => 'temporary'], DeliveryFailure::Deferred],
    'the sender blocked' => [['reason' => 'espblock'], DeliveryFailure::SenderBlocked],
    'the sender blocked for now' => [['severity' => 'temporary', 'reason' => 'espblock'], DeliveryFailure::SenderDeferred],
    'on the bounce list' => [['reason' => 'suppress-bounce'], DeliveryFailure::SuppressedBounce],
    'on the complaint list' => [['reason' => 'suppress-complaint'], DeliveryFailure::SuppressedComplaint],
    'on the unsubscribe list' => [['reason' => 'suppress-unsubscribe'], DeliveryFailure::SuppressedUnsubscribe],
    'any other permanent failure' => [['reason' => 'generic', 'delivery-status' => ['bounce-type' => null]], DeliveryFailure::Other],
    'no failure at all' => [['event' => 'delivered'], null],
]);

describe('reading Mailgun\'s logs and suppressions', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        config()->set('campaigns.mailgun.api_key', 'key-reader');
        config()->set('mail.mailers.campaigns', ['transport' => 'mailgun', 'scheme' => 'api', 'secret' => 'key-sending', 'domain' => 'news.example.com', 'endpoint' => 'api.eu.mailgun.net']);
    });

    it('looks a message up by its uuid in the Logs API, page by page, keeping only its own events', function (): void {
        $this->freezeTime();
        $uuid = (string) Str::uuid();
        $posted = [];

        Http::fake(function (ClientRequest $request) use (&$posted, $uuid): PromiseInterface {
            $posted[] = $request;

            return Http::response(match ($request['pagination']['token'] ?? null) {
                null => mailgunLogs([
                    mailgunLogItem('accepted', ['user-variables' => ['campaign-message' => strtoupper($uuid)]]),
                    // Another email's event a looser filter let through.
                    mailgunLogItem('delivered', ['id' => 'other-email', 'user-variables' => ['campaign-message' => (string) Str::uuid()]]),
                ], 'page-2'),
                default => mailgunLogs([mailgunLogItem('delivered', ['user-variables' => ['campaign-message' => $uuid]])], 'page-2'),
            });
        });

        $events = app(MailgunProvider::class)->eventsForMessage($uuid, now()->subHour());

        expect($events)->toHaveCount(2)
            ->and($events[0]->type)->toBe(FeedbackType::Accepted)
            ->and($events[0]->occurredAt->format('Y-m-d H:i:s.v'))->toBe(Carbon::createFromTimestampUTC(mailgunEvent('accepted')['timestamp'])->format('Y-m-d H:i:s.v'))
            ->and($events[0]->replayKey)->toBeNull()
            ->and($events[1]->type)->toBe(FeedbackType::Delivered)
            ->and($events[1]->messageUuid)->toBe($uuid)
            // The second page named itself as the next: no third request.
            ->and($posted)->toHaveCount(2)
            ->and($posted[0]->url())->toBe('https://api.eu.mailgun.net/v1/analytics/logs')
            ->and($posted[0]->method())->toBe('POST')
            ->and($posted[0]->header('Authorization'))->toBe(['Basic '.base64_encode('api:key-reader')])
            ->and($posted[0]->data())->toBe([
                'start' => now()->subHour()->utc()->toRfc2822String(),
                'end' => now()->utc()->toRfc2822String(),
                'events' => ['accepted', 'delivered', 'failed', 'opened', 'clicked', 'unsubscribed', 'complained'],
                'filter' => ['AND' => [['attribute' => 'user_variables', 'comparator' => 'contains', 'values' => [['label' => $uuid, 'value' => $uuid]]]]],
                'pagination' => ['sort' => 'timestamp:asc', 'limit' => 100],
            ])
            ->and($posted[1]['pagination'])->toBe(['sort' => 'timestamp:asc', 'limit' => 100, 'token' => 'page-2']);
    });

    it('tells nothing about a message whose lookup leaves pages unread', function (): void {
        $page = 0;

        // A filter matching far more than the one email: page after page of
        // others' events.
        Http::fake(function () use (&$page): PromiseInterface {
            $page++;

            return Http::response(mailgunLogs([mailgunLogItem('delivered', ['id' => "other-{$page}", 'user-variables' => ['campaign-message' => (string) Str::uuid()]])], "page-{$page}"));
        });

        expect(app(MailgunProvider::class)->eventsForMessage((string) Str::uuid(), now()->subHour()))->toBeNull()
            ->and($page)->toBe(5);
    });

    it('replays a domain\'s events oldest first from no further back than the logs reach, and asks nothing for an empty period', function (): void {
        $this->freezeTime();
        $posted = [];

        Http::fake(function (ClientRequest $request) use (&$posted): PromiseInterface {
            $posted[] = $request->data();

            return Http::response(count($posted) === 1 ? mailgunLogs([mailgunLogItem('opened')], 'next') : mailgunLogs([], 'next'));
        });

        $provider = app(MailgunProvider::class);
        $events = $provider->eventsBetween('news.example.com', now()->subDays(30), now()->subMinutes(30));

        // Lazy: nothing is asked until the events are read.
        expect($posted)->toBe([])
            ->and(iterator_to_array($events, false))->toHaveCount(1)
            ->and($posted)->toHaveCount(2)
            ->and($posted[0]['start'])->toBe(now()->subDays(5)->addMinute()->utc()->toRfc2822String())
            ->and($posted[0]['end'])->toBe(now()->subMinutes(30)->utc()->toRfc2822String())
            ->and($posted[0]['filter'])->toBe(['AND' => [['attribute' => 'domain', 'comparator' => '=', 'values' => [['label' => 'news.example.com', 'value' => 'news.example.com']]]]])
            // Every type the package reads but accepted, which the others prove.
            ->and($posted[0]['events'])->toBe(['delivered', 'failed', 'opened', 'clicked', 'unsubscribed', 'complained'])
            ->and($provider->logDays())->toBe(5);

        config()->set('campaigns.mailgun.log_days', 30);

        expect(iterator_to_array($provider->eventsBetween('news.example.com', now(), now()->subMinute())))->toBe([])
            ->and($posted)->toHaveCount(2)
            ->and($provider->logDays())->toBe(30);
    });

    it('reads a log item as the webhook\'s event: its ISO time, its variables as a string, its delivery time kept as a number', function (): void {
        $docs = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Mailgun/logs.json'), true);

        Http::fake(['*' => Http::response(mailgunLogs([
            $docs['items'][0],
            mailgunLogItem('clicked', ['user-variables' => ['campaign-message' => 'abc'], 'delivered-at' => 1770146431.5]),
        ]))]);

        [$failure, $clicked] = iterator_to_array(app(MailgunProvider::class)->eventsBetween('news.example.com', now()->subHour(), now()), false);

        expect($failure->providerEventId)->toBe('JDhSWf9mT5OFxCkZfpDU5d')
            ->and($failure->type)->toBe(FeedbackType::Failed)
            ->and($failure->occurredAt->toIso8601ZuluString('millisecond'))->toBe('2024-07-08T16:30:18.530Z')
            ->and($failure->code)->toBe(680)
            ->and($failure->messageUuid)->toBeNull()
            ->and($clicked->type)->toBe(FeedbackType::Clicked)
            ->and($clicked->messageUuid)->toBe('abc')
            ->and($clicked->payload['delivered-at'])->toBe(1770146431.5);
    });

    it('reads every suppression list to its end, scrubbing addresses from the reasons, and never follows a page off Mailgun\'s API', function (): void {
        $docs = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Mailgun/bounces.json'), true);
        $base = 'https://api.eu.mailgun.net/v3/news.example.com';
        $asked = [];

        Http::fake(function (ClientRequest $request) use (&$asked, $docs, $base): PromiseInterface {
            $asked[] = $request->url();

            return Http::response(match ($request->url()) {
                "{$base}/bounces?limit=1000" => [
                    'items' => [...$docs['items'], ['address' => 'b@example.org', 'code' => 550, 'error' => 'Mailbox b@example.org unknown', 'created_at' => 'not a date'], ['code' => '550']],
                    'paging' => ['next' => "{$base}/bounces?page=next&address=b%40example.org"],
                ],
                "{$base}/bounces?page=next&address=b%40example.org" => ['items' => [], 'paging' => ['next' => "{$base}/bounces?page=next&address=b%40example.org"]],
                "{$base}/complaints?limit=1000" => ['items' => [['address' => 'c@example.org', 'created_at' => 'Thu, 11 Dec 2025 01:49:40 UTC']], 'paging' => ['next' => "{$base}/complaints?limit=1000"]],
                default => ['items' => [
                    ['address' => 'u@example.org', 'tags' => ['*']],
                    // Unsubscribed from one tag only, which no campaign carries.
                    ['address' => 'tag@example.org', 'tags' => ['spring-sale']],
                ], 'paging' => ['next' => 'https://elsewhere.example.com/unsubscribes?page=next']],
            });
        });

        $read = [];
        $exception = null;

        try {
            foreach (app(MailgunProvider::class)->suppressions('news.example.com') as $suppression) {
                $read[] = $suppression;
            }
        } catch (CampaignsException $exception) {
        }

        expect(array_map(fn (Suppression $s): array => [$s->email, $s->type, $s->since?->toIso8601String(), $s->reason], $read))->toBe([
            ['foo@bar.com', SuppressionType::Bounce, '2025-12-11T01:49:40+00:00', '550 No such mailbox'],
            ['b@example.org', SuppressionType::Bounce, null, '550 Mailbox [address] unknown'],
            // A page naming itself as the next ends the list.
            ['c@example.org', SuppressionType::Complaint, '2025-12-11T01:49:40+00:00', null],
            ['u@example.org', SuppressionType::Unsubscribe, null, null],
        ])
            ->and($exception?->getMessage())->toContain('away from its API')
            ->and($asked)->toHaveCount(4)
            ->each->toStartWith('https://api.eu.mailgun.net/');
    });

    it('looks one address up on each list, a 404 meaning it isn\'t there once the domain is known', function (): void {
        Http::fake([
            'api.eu.mailgun.net/v3/domains/news.example.com' => Http::response(['domain' => ['name' => 'news.example.com']]),
            'api.eu.mailgun.net/v3/news.example.com/bounces/a%2Bb%40example.org' => Http::response(['address' => 'a+b@example.org', 'code' => '550', 'error' => 'No such mailbox', 'created_at' => 'Thu, 11 Dec 2025 01:49:40 UTC']),
            'api.eu.mailgun.net/v3/news.example.com/complaints/a%2Bb%40example.org' => Http::response(['message' => 'No spam complaints found for this address'], 404),
            'api.eu.mailgun.net/v3/news.example.com/unsubscribes/a%2Bb%40example.org' => Http::response(['address' => 'a+b@example.org', 'tags' => ['*'], 'created_at' => 'Thu, 11 Dec 2025 01:49:40 UTC']),
            'api.eu.mailgun.net/v3/domains/elsewhere.example.com' => Http::response(['message' => 'Domain not found'], 404),
        ]);

        $held = app(MailgunProvider::class)->suppressionsFor('news.example.com', 'a+b@example.org');

        expect(array_map(fn (Suppression $s): SuppressionType => $s->type, $held))->toBe([SuppressionType::Bounce, SuppressionType::Unsubscribe])
            ->and($held[0]->email)->toBe('a+b@example.org')
            ->and(fn (): array => app(MailgunProvider::class)->suppressionsFor('elsewhere.example.com', 'a+b@example.org'))
            ->toThrow(CampaignsException::class, 'Mailgun has no domain elsewhere.example.com');
    });

    it('asks for the marketing domain by the Mailgun domain the campaigns mailer sends through', function (): void {
        config()->set('campaigns.mail.domain', 'example.com');
        config()->set('mail.mailers.campaigns.domain', 'mg.example.com');
        Http::fake(fn (ClientRequest $request): PromiseInterface => Http::response(str_contains($request->url(), '/domains/') ? ['domain' => []] : mailgunLogs([]), str_contains($request->url(), '/bounces/') || str_contains($request->url(), '/complaints/') || str_contains($request->url(), '/unsubscribes/') ? 404 : 200));

        app(MailgunProvider::class)->suppressionsFor('example.com', 'a@example.org');
        iterator_to_array(app(MailgunProvider::class)->suppressions('example.com'));
        iterator_to_array(app(MailgunProvider::class)->eventsBetween('example.com', now()->subHour(), now()));
        iterator_to_array(app(MailgunProvider::class)->eventsBetween('other.example.org', now()->subHour(), now()));

        $domain = fn (ClientRequest $request): mixed => data_get($request->data(), 'filter.AND.0.values.0.value');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.eu.mailgun.net/v3/mg.example.com/bounces/a%40example.org');
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.eu.mailgun.net/v3/mg.example.com/unsubscribes?limit=1000');
        Http::assertSent(fn (ClientRequest $request): bool => $domain($request) === 'mg.example.com');
        Http::assertSent(fn (ClientRequest $request): bool => $domain($request) === 'other.example.org');
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/v3/example.com/') || $domain($request) === 'example.com');
    });

    it('reads from the region the config names, else the campaigns mailer\'s, else Laravel\'s Mailgun service\'s, else the US', function (array $config, string $host): void {
        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        Http::fake(fn (ClientRequest $request): PromiseInterface => Http::response([], str_contains($request->url(), '/domains/') ? 200 : 404));

        app(MailgunProvider::class)->suppressionsFor('news.example.com', 'a@example.org');

        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), "https://{$host}/v3/"));
    })->with([
        'the config' => [['campaigns.mailgun.endpoint' => 'https://api.mailgun.net/'], 'api.mailgun.net'],
        'the mailer' => [[], 'api.eu.mailgun.net'],
        'the service' => [['mail.mailers.campaigns' => ['transport' => 'mailgun', 'scheme' => 'api'], 'services.mailgun.endpoint' => 'api.eu.mailgun.net'], 'api.eu.mailgun.net'],
        'Symfony\'s default' => [['mail.mailers.campaigns.endpoint' => 'default'], 'api.mailgun.net'],
        'none' => [['mail.mailers.campaigns' => ['transport' => 'mailgun', 'secret' => 'key']], 'api.mailgun.net'],
    ]);

    it('refuses to read without a reading key, and fails without the address it asked for or Mailgun\'s answer', function (Closure $answer, string $says): void {
        Http::fake(['*' => $answer]);

        $read = fn (): array => app(MailgunProvider::class)->suppressionsFor('news.example.com', 'person@example.org');
        $exception = null;

        try {
            $read();
        } catch (CampaignsException $exception) {
        }

        expect($exception?->getMessage())->toContain($says)
            ->not->toContain('person')
            ->and($exception?->getPrevious())->toBeNull();

        config()->set('campaigns.mailgun.api_key', '');
        Http::fake(fn (): never => throw new RuntimeException('A request went out.'));

        expect($read)->toThrow(CampaignsException::class, 'CAMPAIGNS_MAILGUN_API_KEY');
    })->with([
        'a refused key' => [fn (): PromiseInterface => Http::response(['message' => 'Invalid private key for person@example.org'], 401), 'Mailgun answered 401'],
        'a lost connection' => [fn (): never => throw new ConnectionException('cURL error 7 for https://api.eu.mailgun.net/v3/news.example.com/bounces/person%40example.org'), 'couldn\'t be reached (ConnectionException)'],
        'an answer that isn\'t JSON' => [fn (): PromiseInterface => Http::response('<html>person@example.org</html>'), 'couldn\'t be read'],
    ]);
});

describe('reading Mailgun\'s metrics', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        config()->set('campaigns.mailgun.api_key', 'key-reader');
        config()->set('campaigns.mail.from', ['address' => 'news@example.com', 'name' => 'Example News']);
        config()->set('mail.mailers.campaigns', ['transport' => 'mailgun', 'scheme' => 'api', 'secret' => 'key-sending', 'domain' => 'news.example.com', 'endpoint' => 'api.eu.mailgun.net']);
    });

    it('reads a domain\'s days with explicit times inside the period, keeping only its days', function (): void {
        $this->travelTo(Carbon::parse('2026-02-03 14:30:00', 'UTC'));
        $answer = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Mailgun/metrics.json'), true);
        $posted = [];

        Http::fake(function (ClientRequest $request) use (&$posted, &$answer): PromiseInterface {
            $posted[] = $request;

            return Http::response($answer);
        });

        // Mailgun rounds the start down to its day and answers that day too:
        // the day before the period is dropped.
        $metrics = app(MailgunProvider::class)->domainMetrics('example.com', now()->subDay(), now());

        expect($metrics->days)->toBe([
            '2026-02-02' => ['sent' => 340, 'forwarded' => 0, 'hard_bounces' => 3, 'complaints' => 1, 'unsubscribes' => 2],
            '2026-02-03' => ['sent' => 75, 'forwarded' => 1, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0],
        ])
            ->and($metrics->totals())->toBe(['sent' => 415, 'forwarded' => 1, 'hard_bounces' => 3, 'complaints' => 1, 'unsubscribes' => 2])
            ->and($posted[0]->url())->toBe('https://api.eu.mailgun.net/v1/analytics/metrics')
            ->and($posted[0]->header('Authorization'))->toBe(['Basic '.base64_encode('api:key-reader')])
            // Explicit times, the end now rather than the next day's first
            // instant, which Mailgun would read as a whole extra day; the
            // marketing domain asked for by the domain its mailer sends
            // through.
            ->and($posted[0]->data())->toBe([
                'start' => 'Mon, 02 Feb 2026 14:30:00 +0000',
                'end' => 'Tue, 03 Feb 2026 14:30:00 +0000',
                'resolution' => 'day',
                'dimensions' => ['time'],
                'metrics' => ['accepted_outgoing_count', 'accepted_incoming_count', 'hard_bounces_count', 'complained_count', 'unsubscribed_count'],
                'filter' => ['AND' => [['attribute' => 'domain', 'comparator' => '=', 'values' => [['label' => 'news.example.com', 'value' => 'news.example.com']]]]],
                'include_aggregates' => false,
            ]);

        // A day Mailgun leaves out counts 0; a rate it gives as a string, or
        // a count it doesn't, is never read as a number of emails.
        unset($answer['items'][1]);
        $answer['items'][2]['metrics'] = ['accepted_outgoing_count' => '75', 'hard_bounces_count' => 'n/a', 'delivered_rate' => '0.9070'];

        expect(app(MailgunProvider::class)->domainMetrics('other.example.org', now()->subDays(2), now())->days)->toBe([
            '2026-02-01' => ['sent' => 120, 'forwarded' => 4, 'hard_bounces' => 2, 'complaints' => 0, 'unsubscribes' => 1],
            '2026-02-02' => ['sent' => 0, 'forwarded' => 0, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0],
            '2026-02-03' => ['sent' => 75, 'forwarded' => 0, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0],
        ])
            ->and($posted[1]['filter']['AND'][0]['values'][0]['value'])->toBe('other.example.org');
    });

    it('fails without Mailgun\'s answer when the metrics can\'t be read', function (): void {
        Http::fake(['*' => Http::response(['message' => 'Invalid private key'], 401)]);

        expect(fn (): mixed => app(MailgunProvider::class)->domainMetrics('example.com', now()->subDay(), now()))
            ->toThrow(CampaignsException::class, 'Mailgun answered 401 when its logs, suppressions or metrics were read. campaigns.mailgun.api_key must be an account API key');
    });
});
