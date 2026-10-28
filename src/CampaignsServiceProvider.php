<?php

declare(strict_types=1);

namespace Mozex\Campaigns;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Process;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class CampaignsServiceProvider extends PackageServiceProvider
{
    protected string $repository = 'https://github.com/mozex/laravel-campaigns';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-campaigns')
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->endWith(fn (InstallCommand $command) => $this->askToStar($command));
            });
    }

    /**
     * Laravel merges a published config with a shallow array_merge, so a
     * published file that predates a key we add to a nested group (pace,
     * stop_loss) would lose that key. Re-merge after the shallow pass.
     */
    public function packageRegistered(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/campaigns.php';

        /** @var array<string, mixed> $published */
        $published = $config->get('campaigns', []);

        $config->set('campaigns', $this->mergeConfig($defaults, $published));
    }

    /**
     * Maps merge recursively and lists are replaced, so a published group
     * fills in from the defaults while a published list stands as written.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    protected function mergeConfig(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $recurse = is_array($value) && ! array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($defaults[$key]);

            /** @var array<string, mixed> $default */
            $default = $recurse ? $defaults[$key] : [];

            /** @var array<string, mixed> $override */
            $override = $recurse ? $value : [];

            $defaults[$key] = $recurse ? $this->mergeConfig($default, $override) : $value;
        }

        return $defaults;
    }

    /**
     * A person gets the question, defaulting to yes. A run nobody can answer
     * (--no-interaction, or no terminal on stdin, as with CI and AI agents)
     * takes that default without asking, so it gets a note explaining the
     * browser tab instead.
     */
    protected function askToStar(InstallCommand $command): void
    {
        if (! $this->isInteractive($command)) {
            $command->info('If laravel-campaigns saves you time, please consider starring it on GitHub: '.$this->repository);

            $this->openInBrowser();

            return;
        }

        if (! $command->confirm('Would you like to show some love by starring laravel-campaigns on GitHub?', true)) {
            return;
        }

        if ($this->openInBrowser()) {
            return;
        }

        $command->info("You'll find laravel-campaigns at ".$this->repository);
    }

    /**
     * Laravel's own rule for prompts (stdin must be a terminal, except under
     * unit tests, where the console output is faked), except that
     * --no-interaction always wins, which keeps that path testable.
     */
    protected function isInteractive(InstallCommand $command): bool
    {
        if ($command->option('no-interaction') === true) {
            return false;
        }

        if ($this->app->runningUnitTests()) {
            return true;
        }

        return defined('STDIN') && stream_isatty(STDIN);
    }

    /**
     * Best effort: any failure returns false. On Linux the opener runs in the
     * background, because xdg-open without a detected desktop runs the browser
     * in the foreground and would hold the command until the browser closes.
     */
    protected function openInBrowser(): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $this->repository],
            'Windows' => ['cmd', '/c', 'start', '', $this->repository],
            default => ['sh', '-c', 'command -v xdg-open > /dev/null && (xdg-open "$1" > /dev/null 2>&1 &)', 'sh', $this->repository],
        };

        try {
            return Process::run($command)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
