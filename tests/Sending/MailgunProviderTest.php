<?php

declare(strict_types=1);

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Sending\MailgunProvider;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
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
