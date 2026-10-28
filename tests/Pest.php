<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
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
