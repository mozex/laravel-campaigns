<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Rows;

it('returns the row another request made a moment earlier, inside a transaction', function (string $driver): void {
    useDatabase($driver);

    // A second connection to the same database stands in for the other
    // request, committing between this transaction's first read and its
    // insert.
    config()->set('database.connections.other', config("database.connections.{$driver}"));

    $found = DB::transaction(function (): Subscriber {
        Subscriber::query()->count();

        DB::connection('other')->table('campaign_subscribers')->insert([
            'email' => 'jane@example.com',
            'soft_bounces' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Rows::firstOrCreate(Subscriber::query(), ['email' => 'jane@example.com']);
    });

    expect($found->wasRecentlyCreated)->toBeFalse()
        ->and($found->email)->toBe('jane@example.com')
        ->and(Subscriber::query()->count())->toBe(1);
})->with(['mysql', 'pgsql']);
