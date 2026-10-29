<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mozex\Campaigns\Content\EmailContent;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Fetching\HostResolver;
use Mozex\Campaigns\Fetching\StaticHostResolver;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
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
