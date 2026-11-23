<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;
use Mozex\Campaigns\CampaignsServiceProvider;

const STAR_QUESTION = 'Would you like to show some love by starring laravel-campaigns on GitHub?';

beforeEach(function (): void {
    Process::fake();

    removePublishedFiles();
});

afterEach(function (): void {
    removePublishedFiles();
});

function removePublishedFiles(): void
{
    @unlink(app()->configPath('campaigns.php'));

    foreach (CampaignsServiceProvider::MIGRATIONS as $name) {
        foreach (glob(app()->databasePath("migrations/*_{$name}.php")) ?: [] as $migration) {
            unlink($migration);
        }
    }
}

function assertOpenedRepository(): void
{
    Process::assertRan(
        fn (PendingProcess $process): bool => in_array('https://github.com/mozex/laravel-campaigns', (array) $process->command, true)
    );
}

it('publishes the config file', function (): void {
    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('campaigns.php')))
        ->toBe(file_get_contents(__DIR__.'/../config/campaigns.php'));
});

it('publishes each migration once, in the order they run', function (): void {
    $this->artisan('campaigns:install')->expectsConfirmation(STAR_QUESTION, 'no')->assertSuccessful();
    $this->artisan('campaigns:install')->expectsConfirmation(STAR_QUESTION, 'no')->assertSuccessful();

    $published = array_map(
        fn (string $name): array => glob($this->app->databasePath("migrations/*_{$name}.php")) ?: [],
        CampaignsServiceProvider::MIGRATIONS,
    );

    expect(CampaignsServiceProvider::MIGRATIONS)->toBe(['create_campaigns_tables', 'upgrade_campaigns_tables_to_0_2']);

    foreach (CampaignsServiceProvider::MIGRATIONS as $index => $name) {
        expect($published[$index])->toHaveCount(1)
            ->and(file_get_contents($published[$index][0]))
            ->toBe(file_get_contents(__DIR__."/../database/migrations/{$name}.php.stub"));
    }

    // The timestamp prefix sets the order an app's migrator runs them in.
    expect(strcmp(basename($published[0][0]), basename($published[1][0])))->toBeLessThan(0);
});

it('publishes only the new migration beside the one an earlier version published', function (): void {
    $earlier = app()->databasePath('migrations/2026_01_01_000000_create_campaigns_tables.php');
    file_put_contents($earlier, '<?php // published by an earlier version');

    // The provider reads the app's migrations when it boots.
    $this->refreshApplication();
    $this->artisan('campaigns:install')->expectsConfirmation(STAR_QUESTION, 'no')->assertSuccessful();

    $upgrade = glob($this->app->databasePath('migrations/*_upgrade_campaigns_tables_to_0_2.php')) ?: [];

    expect(glob($this->app->databasePath('migrations/*_create_campaigns_tables.php')))->toBe([$earlier])
        ->and(file_get_contents($earlier))->toBe('<?php // published by an earlier version')
        ->and($upgrade)->toHaveCount(1)
        ->and(strcmp(basename($earlier), basename($upgrade[0])))->toBeLessThan(0);
});

it('leaves an existing config file alone', function (): void {
    file_put_contents($this->app->configPath('campaigns.php'), '<?php return [];');

    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('campaigns.php')))->toBe('<?php return [];');
});

it('opens the repository when the user agrees to star it', function (): void {
    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->doesntExpectOutputToContain('please consider starring')
        ->doesntExpectOutputToContain("You'll find")
        ->assertSuccessful();

    assertOpenedRepository();
});

it('opens nothing when the user declines', function (): void {
    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the repository link when the browser cannot be opened', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-campaigns at https://github.com/mozex/laravel-campaigns")
        ->assertSuccessful();
});

it('prints the repository link when opening the browser throws', function (): void {
    Process::fake(fn () => throw new RuntimeException('No opener.'));

    $this->artisan('campaigns:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-campaigns at https://github.com/mozex/laravel-campaigns")
        ->assertSuccessful();
});

it('opens the repository with a note instead of asking when nobody can answer', function (): void {
    $this->artisan('campaigns:install', ['--no-interaction' => true])
        ->expectsOutputToContain('If laravel-campaigns saves you time, please consider starring it on GitHub: https://github.com/mozex/laravel-campaigns')
        ->assertSuccessful();

    assertOpenedRepository();

    expect($this->app->configPath('campaigns.php'))->toBeFile();
});

it('lets an app publish the pages\' views and strings, to restyle and translate them', function (): void {
    $published = fn (string $tag): array => array_values(array_map(
        fn (string $path): string => str_replace('\\', '/', $path),
        ServiceProvider::pathsToPublish(CampaignsServiceProvider::class, $tag),
    ));

    expect($published('campaigns-views'))->toHaveCount(1)
        ->and($published('campaigns-views')[0])->toEndWith('views/vendor/campaigns')
        ->and($published('campaigns-translations')[0])->toEndWith('lang/vendor/campaigns');
});
