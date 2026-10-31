<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Manager;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Contracts\Drivers\ReadsMetrics;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;
use Throwable;

/**
 * Builds the drivers by name: the built-in ones, and any an app registers
 * with Campaigns::extend(), whose creator gets the driver's own
 * campaigns.drivers.<name> block and the app. campaigns.driver names the one
 * that sends. A singleton, since it keeps the drivers it built and the
 * creators registered with it (the Campaigns service is scoped, so a creator
 * kept there would be lost between jobs). It lives beside the drivers, being
 * the one place outside them that names each built-in one.
 *
 * Besides the active driver, a driver is configured when its block holds the
 * credential its webhooks are verified with, or when an app registered it,
 * so a switch keeps the old one's late webhooks and log reads working for
 * the emails it sent; the fake only while active. A driver that isn't
 * configured is never built.
 */
class DriverManager extends Manager
{
    /**
     * The name the package's own opens and clicks are recorded under, which
     * no driver may take.
     */
    public const RESERVED = 'campaigns';

    /**
     * What a driver's name may be: it is a path segment of its webhook's
     * address and fits the driver columns.
     */
    public const NAME_PATTERN = '[a-z][a-z0-9_-]{0,49}';

    /**
     * The built-in drivers, by name, with the key of their block that holds
     * the credential their webhooks are verified with (null for none).
     */
    protected const BUILT_IN = [
        'mailgun' => 'webhook_signing_key',
        'smtp' => null,
        'fake' => null,
    ];

    /**
     * Each ability's interface, by the name the overview gives it.
     */
    public const ABILITIES = [
        'webhooks' => ReceivesWebhooks::class,
        'events' => ReadsEvents::class,
        'suppressions' => ReadsSuppressions::class,
        'metrics' => ReadsMetrics::class,
    ];

    /**
     * The active driver's name, as campaigns.driver gives it.
     */
    public function getDefaultDriver(): string
    {
        $name = config('campaigns.driver');

        return is_string($name) ? trim($name) : '';
    }

    /**
     * The driver that sends.
     *
     * @throws CampaignsException when campaigns.driver names none
     */
    public function active(): CampaignDriver
    {
        return $this->get($this->getDefaultDriver());
    }

    /**
     * A driver by name, built once.
     *
     * @throws CampaignsException when nothing by that name is known
     */
    public function get(string $name): CampaignDriver
    {
        if (! $this->known($name)) {
            throw CampaignsException::unknownDriver($name, $this->names());
        }

        $driver = $this->driver($name);

        if (! $driver instanceof CampaignDriver) {
            throw CampaignsException::invalidDriver($name, get_debug_type($driver));
        }

        return $driver;
    }

    /**
     * A configured driver by name, or null for one that isn't (never built).
     *
     * @throws CampaignsException when its creator gives no driver
     */
    public function configured(string $name): ?CampaignDriver
    {
        return $this->isConfigured($name) ? $this->get($name) : null;
    }

    public function isConfigured(string $name): bool
    {
        if (! $this->known($name)) {
            return false;
        }

        if ($name === $this->getDefaultDriver() || isset($this->customCreators[$name])) {
            return true;
        }

        $credential = static::BUILT_IN[$name] ?? null;

        return $credential !== null && filled(config("campaigns.drivers.{$name}.{$credential}"));
    }

    /**
     * Whether a driver by that name can be built: a built-in one, one an app
     * registered, or one a test swapped in.
     */
    public function known(string $name): bool
    {
        return array_key_exists($name, static::BUILT_IN) || isset($this->customCreators[$name]) || isset($this->drivers[$name]);
    }

    /**
     * Every driver name that can be built.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys(static::BUILT_IN), ...array_keys($this->customCreators), ...array_keys($this->drivers)]));
    }

    /**
     * Whether the configured driver by that name reads back what happens to
     * an email after its service takes it (webhooks or logs): deliveries,
     * bounces, spam reports. False for one that only sends, one that isn't
     * configured, and one that can't be built, whose creator's failure is
     * reported unless it is the package's own refusal.
     */
    public function readsBack(string $name): bool
    {
        try {
            $driver = $this->configured($name);
        } catch (CampaignsException) {
            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return $driver instanceof ReceivesWebhooks || $driver instanceof ReadsEvents;
    }

    /**
     * The abilities a driver has, by name.
     *
     * @return list<string>
     */
    public static function abilities(CampaignDriver $driver): array
    {
        return array_keys(array_filter(static::ABILITIES, fn (string $ability): bool => $driver instanceof $ability));
    }

    /**
     * Gives the campaigns mailer the time limits of the built-in driver whose
     * transport it uses, when the app set none, so a stuck request ends before
     * the job sending it. Called while the package registers, before any
     * mailer is built, so none has to be built again. Laravel's own smtp
     * mailer comes with a null timeout, which counts as none.
     */
    public static function boundMailer(Repository $config): void
    {
        $key = 'mail.mailers.'.$config->get('campaigns.mail.mailer');
        $transport = $config->get($key.'.transport');

        if ($transport === 'mailgun' && ! $config->has($key.'.client')) {
            $config->set($key.'.client', MailgunDriver::CLIENT_LIMITS);
        }

        if ($transport === 'smtp' && $config->get($key.'.timeout') === null) {
            $config->set($key.'.timeout', SmtpDriver::TIMEOUT);
        }
    }

    /**
     * Registers a driver: its creator gets the driver's
     * campaigns.drivers.<name> block and the app, and returns a
     * CampaignDriver. A name taken by a built-in driver replaces it.
     *
     * @param  string  $driver
     * @param  Closure(array<string, mixed>, Application): CampaignDriver  $callback
     *
     * @throws CampaignsException for the reserved name, or one that can't be a path segment
     */
    public function extend($driver, Closure $callback): static
    {
        if ($driver === static::RESERVED) {
            throw CampaignsException::reservedDriverName($driver);
        }

        if (preg_match('/\A'.static::NAME_PATTERN.'\z/', $driver) !== 1) {
            throw CampaignsException::invalidDriverName($driver, static::NAME_PATTERN);
        }

        unset($this->drivers[$driver]);

        parent::extend($driver, $callback);

        return $this;
    }

    /**
     * Puts a driver in place of the one built under that name, for tests:
     * the manager keeps what it builds, and the reconciler and the webhook
     * route ask it by name, past any container binding.
     */
    public function swap(string $name, CampaignDriver $driver): static
    {
        $this->drivers[$name] = $driver;

        return $this;
    }

    /**
     * @param  string  $driver
     */
    protected function callCustomCreator($driver): mixed
    {
        $config = config("campaigns.drivers.{$driver}");

        return $this->customCreators[$driver](is_array($config) ? $config : [], $this->container);
    }

    protected function createMailgunDriver(): MailgunDriver
    {
        return new MailgunDriver;
    }

    protected function createSmtpDriver(): SmtpDriver
    {
        return new SmtpDriver;
    }

    protected function createFakeDriver(): FakeDriver
    {
        return new FakeDriver;
    }
}
