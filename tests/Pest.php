<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\StartCampaignAction;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Fetching\HostResolver;
use Mozex\Campaigns\Fetching\StaticHostResolver;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Sending\FakeProvider;
use Mozex\Campaigns\Support\Token;
use Mozex\Campaigns\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

dataset('databases', ['sqlite', 'mysql', 'pgsql']);

/**
 * The actions in a subscriber's consent records, oldest first.
 *
 * @return list<string>
 */
function consentActions(?Subscriber $subscriber): array
{
    return ConsentRecord::query()
        ->where('subscriber_id', $subscriber?->id)
        ->orderBy('id')
        ->get()
        ->map(fn (ConsentRecord $record): string => $record->action->value)
        ->all();
}

/**
 * A sent message and the raw token of its links, as the send job leaves
 * them.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: CampaignMessage, 1: string}
 */
function sentMessage(array $attributes = []): array
{
    $token = Token::generate();

    $message = CampaignMessage::factory()->create([
        'status' => MessageStatus::Sent,
        ...$attributes,
        'token_hash' => Token::hash($token),
    ]);

    return [$message, $token];
}

/**
 * A tiny real image of the given type (2 x 1 pixels), embedded so the tests
 * need no image extension to make one.
 */
function testImage(string $type = 'png'): string
{
    return base64_decode(match ($type) {
        'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWNgYGBgYGAAAAAHAAHV3tbYAAAAAElFTkSuQmCC',
        'gif' => 'R0lGODdhAgABAIAAAAQCBAAAACwAAAAAAgABAAACAgQKADs=',
        'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAEAAUAmJaQAA3AA/v02aAA=',
        'jpeg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAQACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=',
    });
}

/**
 * A zip's bytes, built in a temporary file that is gone before this
 * returns. $links names entries stored as symbolic links, $encrypted ones
 * stored encrypted.
 *
 * @param  array<string, string>  $files
 * @param  list<string>  $links
 * @param  list<string>  $encrypted
 */
function zipOf(array $files, array $links = [], array $encrypted = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'campaigns-test-zip-');
    $zip = new ZipArchive;

    try {
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);

            if (in_array($name, $links, true)) {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0120777 << 16));
            }

            if (in_array($name, $encrypted, true)) {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256, 'secret');
            }
        }

        $zip->close();

        return (string) file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

/**
 * A tool's structured answer, from laravel/mcp's test response, which
 * offers only assertions on it.
 *
 * @return array<string, mixed>
 */
function structuredContent(McpTestResponse $response): array
{
    /** @var array<string, mixed> */
    return (fn (): ?array => $this->structuredContent())->call($response) ?? [];
}

/**
 * Campaigns come from news.example.com, and sending is on.
 */
function sendFromExample(): void
{
    config()->set('campaigns.mail.from', ['address' => 'news@news.example.com', 'name' => 'Example News']);
    config()->set('campaigns.enabled', true);
}

/**
 * A subscriber with a subscription in the given status on each list.
 *
 * @param  list<CampaignList>  $lists
 * @param  array<string, mixed>  $attributes
 */
function subscriberOn(array $lists, array $attributes = [], SubscriptionStatus $status = SubscriptionStatus::Subscribed): Subscriber
{
    $subscriber = Subscriber::factory()->create($attributes);

    foreach ($lists as $list) {
        Subscription::factory()->for($list, 'list')->for($subscriber)->create(['status' => $status]);
    }

    return $subscriber;
}

/**
 * A campaign started on a list of new subscribers, with the given pace: its
 * recipients frozen into pending messages in send order, and sending (or
 * scheduled, given a time). Sending is on and goes to the fake provider.
 *
 * @param  int|list<string>  $recipients  how many, or their addresses
 * @param  array<string, mixed>  $pace
 */
function sendingCampaign(int|array $recipients, array $pace = [], string $key = 'news', string $list = 'readers', ?CarbonInterface $at = null): Campaign
{
    sendFromExample();
    config()->set('campaigns.provider', 'fake');

    $target = CampaignList::query()->where('key', $list)->first() ?? CampaignList::factory()->create(['key' => $list]);
    $emails = is_int($recipients) ? array_map(fn (int $i): string => "{$key}-{$i}@example.org", range(1, $recipients)) : $recipients;

    foreach ($emails as $email) {
        subscriberOn([$target], ['email' => $email]);
    }

    $campaign = app(CreateCampaignAction::class)->execute($key, 'News', EmailSource::contents([campaignEmail()]), ['lists' => [$list]])->campaign;

    return app(StartCampaignAction::class)->execute($campaign, CampaignAudience::for($campaign)->recipients()->count(), $at, $pace);
}

/**
 * $count of a campaign's emails the provider took, written in one go (the
 * stop-loss and the report read thousands): delivered, sent at the given
 * time (an hour ago), to addresses of no subscriber, with the given columns
 * replaced (a hard bounce, a spam report, an unsubscribe).
 *
 * @param  array<string, mixed>  $columns
 */
function sends(Campaign $campaign, int $count, array $columns = [], ?CarbonInterface $at = null): void
{
    $at ??= now()->subHour();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'email' => 'r'.Str::lower(Str::random(16)).'@example.org',
            'position' => random_int(0, 1_000_000),
            'status' => MessageStatus::Delivered->value,
            'sent_at' => $at,
            'delivered_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
            ...$columns,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        CampaignMessage::query()->insert($chunk);
    }
}

/**
 * The columns of a hard bounce, for sends().
 *
 * @return array<string, mixed>
 */
function hardBounce(): array
{
    return ['status' => MessageStatus::Failed->value, 'failed_at' => now(), 'failure_reason' => 'hard_bounce'];
}

/**
 * The provider, once campaigns.provider is "fake".
 */
function fakeProvider(): FakeProvider
{
    /** @var FakeProvider */
    return app(CampaignProvider::class);
}

/**
 * One of Mailgun's documented webhook payloads (its event-data), from
 * tests/Fixtures/Mailgun, with the given fields replaced (nested arrays
 * merge, so ['user-variables' => [...]] turns its [] into an object).
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function mailgunEvent(string $name, array $changes = []): array
{
    /** @var array<string, mixed> $event */
    $event = json_decode((string) file_get_contents(__DIR__."/Fixtures/Mailgun/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return array_replace_recursive($event, $changes);
}

/**
 * One of Mailgun's documented webhook payloads as its Logs API gives the same
 * event (POST /v1/analytics/logs): an ISO "@timestamp" for the Unix
 * "timestamp", user-variables as a JSON string, delivered-at as a time.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function mailgunLogItem(string $name, array $changes = []): array
{
    $event = mailgunEvent($name, $changes);
    $variables = $event['user-variables'] ?? [];

    $event['@timestamp'] = Carbon::createFromTimestampUTC((float) $event['timestamp'])->format('Y-m-d\TH:i:s.v\Z');
    $event['user-variables'] = (string) json_encode($variables === [] ? new stdClass : $variables);
    unset($event['timestamp']);

    if (is_numeric($event['delivered-at'] ?? null)) {
        $event['delivered-at'] = Carbon::createFromTimestampUTC((float) $event['delivered-at'])->format('Y-m-d\TH:i:s.v\Z');
    }

    return $event;
}

/**
 * A Logs API answer in the documented shape (tests/Fixtures/Mailgun/logs.json)
 * holding the given items, and the next page's token (none: the last page).
 *
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function mailgunLogs(array $items, ?string $next = null): array
{
    /** @var array<string, mixed> $answer */
    $answer = json_decode((string) file_get_contents(__DIR__.'/Fixtures/Mailgun/logs.json'), true, flags: JSON_THROW_ON_ERROR);

    $answer['items'] = $items;
    $answer['pagination'] = array_filter(['next' => $next, 'total' => count($items)]);

    return $answer;
}

/**
 * A webhook request's body as Mailgun posts it: the event, and a signature
 * of its timestamp (now, unless given) and token made with the given key.
 *
 * @param  array<string, mixed>  $event
 * @return array{signature: array{timestamp: string, token: string, signature: string}, event-data: array<string, mixed>}
 */
function mailgunWebhook(array $event, ?string $token = null, ?int $timestamp = null, string $key = 'webhook-signing-key'): array
{
    $timestamp = (string) ($timestamp ?? now()->getTimestamp());
    $token ??= Str::random(50);

    return [
        'signature' => [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, $key),
        ],
        'event-data' => $event,
    ];
}

/**
 * One locale's email that meets the revision rules.
 */
function campaignEmail(string $locale = 'en', string $html = '<p>Hello</p>{{footer}}', ?string $text = null): EmailContent
{
    return new EmailContent(locale: $locale, subject: 'News', html: $html, text: $text);
}

/**
 * Pins what host names resolve to, so code that fetches through the
 * package's guard never makes a real DNS lookup.
 *
 * @param  array<string, string|list<string>>  $hosts
 */
function resolveHosts(array $hosts): void
{
    app()->instance(HostResolver::class, new StaticHostResolver($hosts));
}

/**
 * Moves the running test from the in-memory SQLite database to a scratch
 * database on a real MySQL or PostgreSQL server, with the package's tables
 * freshly migrated, or skips the test when no server answers. The defaults
 * match the CI service containers; the CAMPAIGNS_TEST_* variables point at a
 * server with other credentials.
 */
function useDatabase(string $driver): void
{
    if ($driver === 'sqlite') {
        return;
    }

    $prefix = 'CAMPAIGNS_TEST_'.strtoupper($driver).'_';
    $database = 'campaigns_testing';

    $server = [
        'host' => env($prefix.'HOST', '127.0.0.1'),
        'port' => (int) env($prefix.'PORT', $driver === 'pgsql' ? 5432 : 3306),
        'username' => env($prefix.'USERNAME', $driver === 'pgsql' ? 'postgres' : 'root'),
        'password' => env($prefix.'PASSWORD', $driver === 'pgsql' ? 'postgres' : ''),
    ];

    try {
        $pdo = $driver === 'pgsql'
            ? new PDO("pgsql:host={$server['host']};port={$server['port']};dbname=postgres", $server['username'], $server['password'])
            : new PDO("mysql:host={$server['host']};port={$server['port']}", $server['username'], $server['password']);
    } catch (PDOException $exception) {
        // CI starts both servers, so a missing one there is a broken run,
        // not a reason to skip.
        if (env('GITHUB_ACTIONS')) {
            throw $exception;
        }

        test()->markTestSkipped("No {$driver} server answered: {$exception->getMessage()}");
    }

    if ($driver === 'mysql') {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$database}");
    } elseif ($pdo->query("SELECT 1 FROM pg_database WHERE datname = '{$database}'")->fetchColumn() === false) {
        $pdo->exec("CREATE DATABASE {$database}");
    }

    config()->set("database.connections.{$driver}", [
        ...config("database.connections.{$driver}"),
        ...$server,
        'database' => $database,
        // A DB_URL in the environment would otherwise win over the host and
        // database above, and every table there would be dropped.
        'url' => null,
    ]);
    config()->set('database.default', $driver);
    DB::purge($driver);

    Schema::dropAllTables();
    TestCase::runMigrations();
}
