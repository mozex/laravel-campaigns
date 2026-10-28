<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        // Apps commonly run strict models and a shorter default string length,
        // so the package must work under both. The short default also makes
        // an unsized column fail the column-length test.
        Model::shouldBeStrict();
        Builder::defaultStringLength(125);

        parent::setUp();
    }

    /**
     * Testbench's in-memory SQLite leaves foreign keys off, while Laravel's
     * own SQLite config turns them on, and the package relies on its
     * cascades and nulled keys.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        static::runMigrations();
    }

    /**
     * The migrations ship as a stub the app publishes, so they run from the
     * stub here, exactly as an app would get them.
     */
    public static function runMigrations(): void
    {
        (require __DIR__.'/../database/migrations/create_campaigns_tables.php.stub')->up();
    }
}
