<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Aws\SesV2\SesV2Client;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Manager;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ChecksSetup;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Contracts\Drivers\ReadsMetrics;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReadsUsage;
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
 * Besides the active driver, a driver is configured when its block holds a
 * credential it reads back with (its webhooks' key, a key that reads its
 * logs), or when an app registered it, so a switch keeps the old one's late
 * webhooks and log reads working for the emails it sent; the fake only
 * while active. A driver that isn't configured is never built. What a
 * configured driver may do also follows its settings (ChecksSetup).
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
     * The built-in drivers, by name, with the keys of their block that hold
     * a credential they read back with: any of them set makes the driver
     * configured while another sends.
     */
    protected const BUILT_IN = [
        'mailgun' => ['webhook_signing_key', 'api_key'],
        'smtp' => [],
        'ses' => ['webhook_secret'],
        'fake' => [],
    ];

    /**
     * Each built-in driver's setup page, under docs/.
     */
    protected const PAGES = [
        'mailgun' => 'getting-started/mailgun.md',
        'smtp' => 'getting-started/smtp.md',
        'ses' => 'getting-started/ses.md',
    ];

    /**
     * The class a built-in driver needs from a package the app may not have
     * installed, by the driver's name.
     */
    protected const REQUIRES = [
        'ses' => SesV2Client::class,
    ];

    /**
     * Each ability's interface, by the name the overview gives it.
     */
    public const ABILITIES = [
        'webhooks' => ReceivesWebhooks::class,
        'events' => ReadsEvents::class,
        'suppressions' => ReadsSuppressions::class,
        'metrics' => ReadsMetrics::class,
        'usage' => ReadsUsage::class,
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
        if (! $this->known($name) || ! $this->installed($name)) {
            return false;
        }

        if ($name === $this->getDefaultDriver() || isset($this->customCreators[$name])) {
            return true;
        }

        foreach (static::BUILT_IN[$name] ?? [] as $credential) {
            $value = config("campaigns.drivers.{$name}.{$credential}");

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The setup page of a built-in driver by name, under docs/; null for
     * one an app registered, though under a built-in's name.
     */
    public function page(string $name): ?string
    {
        return isset($this->customCreators[$name]) ? null : (static::PAGES[$name] ?? null);
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
     * Whether the package a built-in driver needs is installed: a driver
     * whose package is missing counts as not configured, so its webhooks
     * answer 404 without it being built. A driver an app registered or a
     * test swapped in brings its own.
     */
    public function installed(string $name): bool
    {
        if (isset($this->customCreators[$name]) || isset($this->drivers[$name])) {
            return true;
        }

        $class = static::REQUIRES[$name] ?? null;

        return $class === null || class_exists($class);
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
     * reported unless it is the package's own refusal. Its abilities count
     * only when its configuration sets them up.
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

        return $driver !== null && (static::ability($driver, ReceivesWebhooks::class) !== null || static::ability($driver, ReadsEvents::class) !== null);
    }

    /**
     * The abilities a driver has, by name: the ones its class implements
     * and its configuration sets up.
     *
     * @return list<string>
     */
    public static function abilities(CampaignDriver $driver): array
    {
        $abilities = [];

        foreach (static::ABILITIES as $name => $interface) {
            if ($driver instanceof $interface && static::missingSetup($driver, $interface) === null) {
                $abilities[] = $name;
            }
        }

        return $abilities;
    }

    /**
     * The driver as one of its abilities: itself when its class implements
     * the ability and its configuration sets it up, else null. Every caller
     * asks here, never with instanceof, so an ability whose setting is
     * missing counts as missing everywhere alike.
     *
     * @template T of object
     *
     * @param  class-string<T>  $ability
     * @return ?T
     */
    public static function ability(CampaignDriver $driver, string $ability): ?object
    {
        if (! $driver instanceof $ability) {
            return null;
        }

        return static::missingSetup($driver, $ability) === null ? $driver : null;
    }

    /**
     * What a driver's configuration still lacks for an ability its class
     * implements, written to follow "until"; null when it is set up, and
     * when the class lacks the ability, which no setting gives.
     *
     * @param  class-string  $ability
     */
    public static function missingSetup(CampaignDriver $driver, string $ability): ?string
    {
        if (! $driver instanceof $ability || ! $driver instanceof ChecksSetup) {
            return null;
        }

        $missing = $driver->missingSetup($ability);

        return is_string($missing) && trim($missing) !== '' ? $missing : null;
    }

    /**
     * " until <what the configuration lacks>", for a sentence saying what a
     * driver can't do, or '' when no setting would give the ability.
     *
     * @param  class-string  $ability
     */
    public static function until(CampaignDriver $driver, string $ability): string
    {
        $missing = static::missingSetup($driver, $ability);

        return $missing === null ? '' : " until {$missing}";
    }

    /**
     * Gives the campaigns mailer the time limits of the built-in driver whose
     * transport it uses, when the app set none, so a stuck request ends before
     * the job sending it. Called while the package registers, before any
     * mailer is built, so none has to be built again. Laravel's own smtp
     * mailer comes with a null timeout, which counts as none. An ses-v2
     * mailer's settings are merged over services.ses one key deep, so its
     * own http block would replace one the app set there.
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

        if ($transport === 'ses-v2' && ! $config->has($key.'.http') && ! $config->has('services.ses.http')) {
            $config->set($key.'.http', SesDriver::HTTP_LIMITS);
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

    /**
     * @throws CampaignsException when the AWS SDK isn't installed
     */
    protected function createSesDriver(): SesDriver
    {
        if (! $this->installed('ses')) {
            throw new CampaignsException('The ses driver needs the AWS SDK for PHP: composer require aws/aws-sdk-php.');
        }

        return new SesDriver;
    }

    protected function createFakeDriver(): FakeDriver
    {
        return new FakeDriver;
    }
}
