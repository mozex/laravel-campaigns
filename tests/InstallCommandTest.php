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

    foreach (glob(app()->databasePath('migrations/*_create_campaigns_tables.php')) ?: [] as $migration) {
        unlink($migration);
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

it('publishes the migration once', function (): void {
    $this->artisan('campaigns:install')->expectsConfirmation(STAR_QUESTION, 'no')->assertSuccessful();
    $this->artisan('campaigns:install')->expectsConfirmation(STAR_QUESTION, 'no')->assertSuccessful();

    $published = glob($this->app->databasePath('migrations/*_create_campaigns_tables.php'));

    expect($published)->toHaveCount(1)
        ->and(file_get_contents($published[0]))
        ->toBe(file_get_contents(__DIR__.'/../database/migrations/create_campaigns_tables.php.stub'));
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
